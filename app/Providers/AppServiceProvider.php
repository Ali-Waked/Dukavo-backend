<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\ContentPage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\FaqEntry;
use App\Models\Newsletter;
use App\Models\Order;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Channels\WhatsApp\MetaCloudGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
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
    }

    public function boot(): void
    {
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

        Relation::enforceMorphMap([
            'tenant' => Tenant::class,
            'user' => User::class,
            'store' => Store::class,
            'category' => Category::class,
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'customer' => Customer::class,
            'order' => Order::class,
            'conversation' => Conversation::class,
            'whatsapp_account' => WhatsappAccount::class,
            // Platform-level marketing records that write audit rows.
            'pricing_plan' => PricingPlan::class,
            'faq_entry' => FaqEntry::class,
            'contact_message' => ContactMessage::class,
            'content_page' => ContentPage::class,
            'newsletter' => Newsletter::class,
            'role' => Role::class,
        ]);

        // N+1 detection is free insurance in development and must never reach
        // production, where the overhead is not acceptable.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
