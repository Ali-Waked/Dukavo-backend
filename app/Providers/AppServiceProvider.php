<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Automation\ConversationAssigned;
use App\Events\Automation\ConversationMessageReceived;
use App\Events\Automation\CustomerCreated;
use App\Events\Automation\InventoryLowStock;
use App\Events\Automation\InventoryOutOfStock;
use App\Events\Automation\InventoryRestocked;
use App\Events\Automation\OrderCreated;
use App\Events\Automation\OrderDelivered;
use App\Events\Automation\OrderPaid;
use App\Events\Automation\OrderStatusChanged;
use App\Events\Automation\RecoveryOpportunityDetected;
use App\Events\Automation\ScheduledAutomationEvent;
use App\Listeners\NotifyPasswordReset;
use App\Listeners\RecordFailedLogin;
use App\Listeners\RunAutomationRules;
use App\Models\AiAction;
use App\Models\AiProvider;
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
use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\HelpCategory;
use App\Models\HelpTag;
use App\Models\IntegrationHealthCheck;
use App\Models\Newsletter;
use App\Models\NotificationCampaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PlatformSetting;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\RecoveryOpportunity;
use App\Models\Role;
use App\Models\ShippingZone;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Ai\Contracts\SpeechToTextProvider;
use App\Services\Ai\Contracts\TextToSpeechProvider;
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
use App\Services\Ai\Features\TranslationAssistant;
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
use App\Services\Ai\Providers\Tts\TtsProviderFactory;
use App\Services\Ai\Support\AiCapability;
use App\Services\Channels\ChannelProviderRegistry;
use App\Services\Channels\Connectors\EmailConnector;
use App\Services\Channels\Connectors\MetaMessagingConnector;
use App\Services\Channels\Connectors\SmsConnector;
use App\Services\Channels\Connectors\TelegramConnector;
use App\Services\Channels\Connectors\TikTokConnector;
use App\Services\Channels\Connectors\WhatsAppConnector;
use App\Services\Channels\Connectors\XConnector;
use App\Services\Channels\WebhookSignatureVerifier;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Channels\WhatsApp\MetaCloudGateway;
use App\Services\Currency\Contracts\ExchangeRateProvider;
use App\Services\Currency\CurrencyCatalog;
use App\Services\Currency\Providers\OpenExchangeRateProvider;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\Push\Contracts\PushTransport;
use App\Services\Notifications\Push\FirebasePushTransport;
use App\Services\Recovery\RecoveryService;
use App\Services\Translation\TranslationEntityRegistry;
use App\Services\Translation\TranslationStaleObserver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
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

        // Every channel connector is registered here and resolved by key. A
        // controller, job or service never constructs an adapter itself, so a
        // test can substitute one and a new provider is one registration.
        $this->app->singleton(ChannelProviderRegistry::class, function ($app): ChannelProviderRegistry {
            $verifier = $app->make(WebhookSignatureVerifier::class);

            return new ChannelProviderRegistry([
                $app->make(WhatsAppConnector::class),
                $app->make(TelegramConnector::class),
                new MetaMessagingConnector('instagram', 'instagram', $verifier),
                new MetaMessagingConnector('messenger', 'page', $verifier),
                new EmailConnector($verifier),
                new SmsConnector($verifier),
                new TikTokConnector($verifier),
                new XConnector($verifier),
            ]);
        });

        // Currency configuration is immutable configuration, shared once.
        $this->app->singleton(CurrencyCatalog::class);

        // Exchange rates are reached only through this contract. Tests replace
        // the binding with a fixed quote; production uses the configured
        // provider and fails clearly when it cannot produce a trustworthy rate.
        $this->app->bind(ExchangeRateProvider::class, OpenExchangeRateProvider::class);

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

        // Push is reached only through this contract. Tests bind a recorder;
        // production uses the Firebase Admin SDK when credentials are present.
        $this->app->singleton(PushTransport::class, FirebasePushTransport::class);

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

        // Text-to-speech is the outbound half of the voice loop and is free
        // first: the factory defaults to a self-hosted Piper or Kokoro engine
        // and refuses a paid adapter unless the deployment explicitly opts in.
        // Tests bind a fake here, never a config value.
        $this->app->bind(TextToSpeechProvider::class, fn (): TextToSpeechProvider => TtsProviderFactory::fromConfig(
            (array) config('ai.tts', []),
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
            new FeatureDescriptor(
                key: 'translation',
                label: 'AI translation',
                permissions: ['translations.generate'],
                capabilities: [AiCapability::StructuredOutput],
                queueable: false,
                service: TranslationAssistant::class,
            ),
        ];
    }

    public function boot(): void
    {
        foreach ([
            OrderCreated::class,
            OrderStatusChanged::class,
            OrderPaid::class,
            OrderDelivered::class,
            InventoryLowStock::class,
            InventoryOutOfStock::class,
            InventoryRestocked::class,
            ConversationMessageReceived::class,
            ConversationAssigned::class,
            CustomerCreated::class,
            RecoveryOpportunityDetected::class,
            ScheduledAutomationEvent::class,
        ] as $event) {
            Event::listen($event, RunAutomationRules::class);
        }

        // A completed password reset is a security fact the account holder
        // must be able to see even if the reset email was deleted.
        Event::listen(PasswordReset::class, NotifyPasswordReset::class);

        // A failed login against a known account belongs in that account's
        // security history so the owner can spot an attempt they did not make.
        Event::listen(Failed::class, RecordFailedLogin::class);

        // A Super Admin administers the platform itself, so every ability is
        // granted. Tenant isolation still applies through the model scopes —
        // platform operators must still resolve a tenant to read tenant data.
        Gate::before(function ($user, string $ability): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });

        Password::defaults(function (): Password {
            // The policy is a platform setting, so it is read at runtime rather
            // than frozen into a closure at boot. `ConfigurationApplier` writes
            // the database override into this block before any request runs.
            $policy = (array) config('dukavo.security.password', []);

            $password = Password::min((int) ($policy['min_length'] ?? 12));

            if ((bool) ($policy['require_letters'] ?? true)) {
                $password->letters();
            }

            if ((bool) ($policy['require_mixed_case'] ?? true)) {
                $password->mixedCase();
            }

            if ((bool) ($policy['require_numbers'] ?? true)) {
                $password->numbers();
            }

            if ((bool) ($policy['require_symbols'] ?? true)) {
                $password->symbols();
            }

            if ((bool) ($policy['require_uncompromised'] ?? true)) {
                $password->uncompromised();
            }

            return $password;
        });

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

        // Marketing unsubscribe links are opened by anonymous recipients, so the
        // limit is IP-scoped. A real recipient clicks once; the cap only stops a
        // client from probing token values in bulk.
        RateLimiter::for('unsubscribe', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('unsubscribe|'.$request->ip()));

        // Help Center search is type-ahead, so the budget accommodates one
        // query per keystroke-debounce. Keyed on the signed-in account and,
        // failing that, the client IP — the public marketing surface and the
        // authenticated surface share this budget, so a visitor cannot switch
        // surfaces to double it.
        RateLimiter::for('help-search', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('help-search|'.($request->user()?->getKey() ?? $request->ip())));

        // Help feedback writes a row and moves a counter. Duplicate votes are
        // already refused server-side (per account, per salted IP); the cap
        // exists so a scripted client cannot hammer the endpoint while the
        // deduplication lookup runs.
        RateLimiter::for('help-feedback', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('help-feedback|'.($request->user()?->getKey() ?? $request->ip())));

        // AI endpoints are expensive and identity-bound, so the burst guard is
        // keyed on the authenticated user and, failing that, the client IP.
        RateLimiter::for('ai', fn (Request $request): Limit => Limit::perMinute((int) config('ai.transport.rate_per_minute', 20))
            ->by('ai|'.($request->user()?->getKey() ?? $request->ip())));

        // Device registration is called on every app boot and token refresh.
        // The limit stops a runaway client from churning rows while leaving
        // room for normal foreground/background transitions.
        RateLimiter::for('notification-devices', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('notification-devices|'.($request->user()?->getKey() ?? $request->ip())));

        // Campaign dispatch paces provider traffic independently of the queue
        // worker count, so scaling workers does not multiply the send rate.
        // The same limiter is consulted by the queue middleware, which passes
        // the job rather than an HTTP request, so the subject is not typed.
        RateLimiter::for('notification-campaigns', fn (mixed $subject = null): Limit => Limit::perMinute(
            (int) config('dukavo.notifications.broadcast.per_minute', 600),
        )->by('notification-campaigns'));

        // Public checkout writes real orders and reserves real stock. Keying the
        // limit on IP plus the store slug stops one client from emptying a
        // shopfront while still allowing shared NATs to browse and order.
        RateLimiter::for('storefront-checkout', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('storefront-checkout|'.$request->ip().'|'.$request->route('storeSlug')));
        RateLimiter::for('storefront', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('storefront|'.$request->ip()));
        // Starting a new hosted payment is a financial action; keep it much
        // tighter than ordinary storefront polling.
        RateLimiter::for('storefront-payment', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('storefront-payment|'.$request->ip().'|'.$request->route('storeSlug')));
        // Provider callbacks must remain deliverable even during a retry storm,
        // but an anonymous flood must not reach signature verification for free.
        RateLimiter::for('payment-webhook', fn (Request $request): Limit => Limit::perMinute(300)
            ->by('payment-webhook|'.$request->ip().'|'.$request->route('provider')));

        // Channel webhooks are anonymous and can be chatty (a busy Telegram bot
        // or a batch of delivery receipts). The limit is per IP and channel so
        // one noisy provider cannot exhaust another's budget, while leaving room
        // for a genuine burst. Signature verification happens before any write.
        RateLimiter::for('channel-webhook', fn (Request $request): Limit => Limit::perMinute(600)
            ->by('channel-webhook|'.$request->ip().'|'.$request->route('channel')));

        Relation::enforceMorphMap([
            'tenant' => Tenant::class,
            'user' => User::class,
            'store' => Store::class,
            'shipping_zone' => ShippingZone::class,
            'category' => Category::class,
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'product_image' => ProductImage::class,
            'bundle' => Bundle::class,
            'customer' => Customer::class,
            'customer_memory' => CustomerMemory::class,
            'customer_summary' => CustomerSummary::class,
            'order' => Order::class,
            'payment' => Payment::class,
            'payment_refund' => PaymentRefund::class,
            'store_payment_account' => StorePaymentAccount::class,
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
            'notification_campaign' => NotificationCampaign::class,
            'role' => Role::class,
            'ai_action' => AiAction::class,
            'ai_provider' => AiProvider::class,
            'automation_rule' => AutomationRule::class,
            'automation_task' => AutomationTask::class,
            // Help Center records that carry ledger translations.
            'help_category' => HelpCategory::class,
            'help_article' => HelpArticle::class,
            'help_tag' => HelpTag::class,
            'help_article_feedback' => HelpArticleFeedback::class,
            // Platform configuration writes audit rows against the setting row
            // so the history page can filter by key without a second table.
            'platform_setting' => PlatformSetting::class,
            'integration_health_check' => IntegrationHealthCheck::class,
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

        // Source changes make existing translations stale, never silently
        // overwrite them. Every translatable model is registered in one place,
        // so adding an entity to the registry also gives it stale detection.
        $translationEntities = $this->app->make(TranslationEntityRegistry::class);

        foreach ($translationEntities->all() as $entity) {
            $entity->model::observe(TranslationStaleObserver::class);
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
