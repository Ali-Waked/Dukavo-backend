<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Automation\ConversationMessageReceived;
use App\Events\Automation\InventoryLowStock;
use App\Events\Automation\OrderCreated;
use App\Events\Automation\OrderStatusChanged;
use App\Events\Automation\RecoveryOpportunityDetected;
use App\Listeners\RunAutomationRules;
use App\Models\AiAction;
use App\Models\AutomationRule;
use App\Models\AutomationTask;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\ContentPage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\CustomerMemory;
use App\Models\CustomerSummary;
use App\Models\FaqEntry;
use App\Models\Newsletter;
use App\Models\Order;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecoveryOpportunity;
use App\Models\Role;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Ai\Contracts\SpeechToTextProvider;
use App\Services\Ai\Features\BundleExplanationAssistant;
use App\Services\Ai\Features\ConversationAssistant;
use App\Services\Ai\Features\ConversationSummary;
use App\Services\Ai\Features\CustomerSummaryAssistant;
use App\Services\Ai\Features\DashboardInsights;
use App\Services\Ai\Features\FeatureDescriptor;
use App\Services\Ai\Features\FeatureRegistry;
use App\Services\Ai\Features\MerchantCopilot;
use App\Services\Ai\Features\NewsletterAssistant;
use App\Services\Ai\Features\RecoveryAssistant;
use App\Services\Ai\Features\SalesCopilot;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;
use App\Services\Ai\Governance\Executors\ConversationSendMessageExecutor;
use App\Services\Ai\Governance\Executors\CustomerCreateExecutor;
use App\Services\Ai\Governance\Executors\InventoryStockAdjustmentExecutor;
use App\Services\Ai\Governance\Executors\OrderCreateExecutor;
use App\Services\Ai\Governance\Executors\OrderTransitionExecutor;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Ai\Providers\ConfiguredAiProviderFactory;
use App\Services\Ai\Providers\OpenAiSpeechToTextProvider;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\AiCapability;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Channels\WhatsApp\MetaCloudGateway;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Recovery\RecoveryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request. Scoped (not singleton) bindings would be
        // wrong for queue workers where a job may act for a tenant explicitly,
        // so the context is a singleton that middleware resets.
        $this->app->singleton(TenantContext::class);

        // The WhatsApp provider is reached only through its contract, so tests
        // and other channels can substitute an implementation.
        $this->app->bind(WhatsAppGateway::class, MetaCloudGateway::class);

        // The set of capabilities AI may propose is closed and explicit. Every
        // executor is registered here, so an action type cannot exist in a
        // request unless the server also has the code to carry it out.
        $this->app->singleton(AiActionExecutorRegistry::class, fn ($app): AiActionExecutorRegistry => new AiActionExecutorRegistry([
            $app->make(InventoryStockAdjustmentExecutor::class),
            $app->make(OrderCreateExecutor::class),
            $app->make(OrderTransitionExecutor::class),
            $app->make(ConversationSendMessageExecutor::class),
            $app->make(CustomerCreateExecutor::class),
        ]));

        // The event catalogue is immutable, so it is built once and shared
        // rather than rebuilt on every resolve by the dispatcher and settings.
        $this->app->singleton(NotificationEventCatalog::class);

        // Provider adapters are built only through this factory, so the
        // registry can support more than one backend without any caller
        // knowing which one is live. Tests replace the factory, never config.
        $this->app->bind(AiProviderFactory::class, ConfiguredAiProviderFactory::class);

        // Speech-to-text is a separate capability from chat completions. Tests
        // replace this binding with an in-memory provider; production uses the
        // configured OpenAI-compatible endpoint, or fails clearly when unset.
        $this->app->bind(SpeechToTextProvider::class, fn (): SpeechToTextProvider => new OpenAiSpeechToTextProvider(
            (array) config('ai.transcription', []),
        ));

        // The catalogue of use cases. It is a singleton so a descriptor
        // registered here is the same one the availability endpoint and the
        // queue job read. Adding a capability means adding one descriptor.
        $this->app->singleton(FeatureRegistry::class, function ($app): FeatureRegistry {
            $registry = new FeatureRegistry(
                $app->make(ProviderRegistry::class),
                $app->make(TenantContext::class),
            );

            foreach (self::aiUseCases() as $descriptor) {
                $registry->register($descriptor);
            }

            return $registry;
        });
    }

    /**
     * Every AI use case the application offers, and what it needs to run.
     *
     * The list is the single place a use case is described. A descriptor names
     * the service that implements it, so the catalogue, the queue and the
     * endpoint all agree on what exists.
     *
     * @return list<FeatureDescriptor>
     */
    private static function aiUseCases(): array
    {
        return [
            new FeatureDescriptor(
                key: 'reply',
                label: 'Reply draft',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: ConversationAssistant::class,
            ),
            new FeatureDescriptor(
                key: 'insights',
                label: 'Workspace briefing',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: DashboardInsights::class,
            ),
            new FeatureDescriptor(
                key: 'content',
                label: 'Newsletter draft',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: NewsletterAssistant::class,
            ),
            new FeatureDescriptor(
                key: 'summary',
                label: 'Conversation summary',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: true,
                service: ConversationSummary::class,
            ),
            new FeatureDescriptor(
                key: 'customer_summary',
                label: 'Customer summary',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: CustomerSummaryAssistant::class,
            ),
            new FeatureDescriptor(
                key: 'bundle_explanation',
                label: 'Bundle explanation',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: BundleExplanationAssistant::class,
            ),
            new FeatureDescriptor(
                key: 'recovery',
                label: 'Recovery follow-up draft',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: RecoveryAssistant::class,
            ),
            new FeatureDescriptor(
                key: 'sales_copilot',
                label: 'Sales copilot',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: SalesCopilot::class,
            ),
            new FeatureDescriptor(
                key: 'merchant_copilot',
                label: 'Merchant copilot',
                permissions: ['ai.use'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: MerchantCopilot::class,
            ),
        ];
    }

    public function boot(): void
    {
        foreach ([
            OrderCreated::class,
            OrderStatusChanged::class,
            InventoryLowStock::class,
            ConversationMessageReceived::class,
            RecoveryOpportunityDetected::class,
        ] as $event) {
            Event::listen($event, RunAutomationRules::class);
        }

        // A Super Admin administers the platform itself, so every ability is
        // granted. Tenant isolation still applies through the model scopes —
        // platform operators must still resolve a tenant to read tenant data.
        Gate::before(function ($user, string $ability): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8)->letters()->mixedCase()->numbers());

        // Anonymous write endpoints on the marketing surface. The keys are IP
        // scoped because the caller has no identity to key on; the limits are
        // deliberately far below the global `api` throttle so a single client
        // cannot mass-submit forms. The login limiter lives in the Fortify
        // provider because it belongs to that package's flow.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('register|'.$request->ip()));

        RateLimiter::for('waitlist', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('waitlist|'.$request->ip()));

        RateLimiter::for('contact', fn (Request $request): Limit => Limit::perMinute(3)
            ->by('contact|'.$request->ip()));

        // Newsletter subscribe and unsubscribe share one budget: both are the
        // same anonymous lead-capture form, and the shared cap keeps a single
        // client from probing addresses over either endpoint.
        RateLimiter::for('newsletter', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('newsletter|'.$request->ip()));

        // AI endpoints are expensive and identity-bound, so the burst guard is
        // keyed on the authenticated user and, failing that, the client IP.
        RateLimiter::for('ai', fn (Request $request): Limit => Limit::perMinute((int) config('ai.transport.rate_per_minute', 20))
            ->by('ai|'.($request->user()?->getKey() ?? $request->ip())));

        Relation::enforceMorphMap([
            'tenant' => Tenant::class,
            'user' => User::class,
            'store' => Store::class,
            'category' => Category::class,
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'bundle' => Bundle::class,
            'customer' => Customer::class,
            'customer_memory' => CustomerMemory::class,
            'customer_summary' => CustomerSummary::class,
            'order' => Order::class,
            'conversation' => Conversation::class,
            'recovery_opportunity' => RecoveryOpportunity::class,
            'stock_transfer' => StockTransfer::class,
            'whatsapp_account' => WhatsappAccount::class,
            // Platform-level marketing records that write audit rows.
            'pricing_plan' => PricingPlan::class,
            'faq_entry' => FaqEntry::class,
            'contact_message' => ContactMessage::class,
            'content_page' => ContentPage::class,
            'newsletter' => Newsletter::class,
            'role' => Role::class,
            'ai_action' => AiAction::class,
            'automation_rule' => AutomationRule::class,
            'automation_task' => AutomationTask::class,
        ]);

        // N+1 detection is free insurance in development and must never reach
        // production, where the overhead is not acceptable.
        // An inbound customer message is a recovery signal: it may answer an
        // earlier follow-up, or be an explicit opt-out. The listener must never
        // break message ingestion, so provider-independent recovery failures
        // are reported and swallowed while the message itself stays committed.
        ConversationMessage::created(function (ConversationMessage $message): void {
            try {
                $this->app->make(RecoveryService::class)->handleInboundMessage($message);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
