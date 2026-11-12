<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Ai\AiActionController;
use App\Http\Controllers\Api\V1\Ai\AiAvailabilityController;
use App\Http\Controllers\Api\V1\Ai\AiSummaryController;
use App\Http\Controllers\Api\V1\Ai\ContentAssistantController;
use App\Http\Controllers\Api\V1\Ai\ConversationAssistantController;
use App\Http\Controllers\Api\V1\Ai\InsightsController;
use App\Http\Controllers\Api\V1\Ai\MerchantCopilotController;
use App\Http\Controllers\Api\V1\Ai\SalesCopilotController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Automation\AutomationInstallationController;
use App\Http\Controllers\Api\V1\Automation\AutomationMetaController;
use App\Http\Controllers\Api\V1\Automation\AutomationRuleController;
use App\Http\Controllers\Api\V1\Automation\AutomationRunController;
use App\Http\Controllers\Api\V1\Automation\AutomationTaskController;
use App\Http\Controllers\Api\V1\Automation\AutomationTemplateController;
use App\Http\Controllers\Api\V1\BundleController;
use App\Http\Controllers\Api\V1\BundleRecommendationController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationEventController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerCrmController;
use App\Http\Controllers\Api\V1\CustomerIdentityController;
use App\Http\Controllers\Api\V1\CustomerMemoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Help\HelpCenterController as AuthenticatedHelpCenterController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryIntelligenceController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\Marketing\ContactController;
use App\Http\Controllers\Api\V1\Marketing\ContentPageController as MarketingContentPageController;
use App\Http\Controllers\Api\V1\Marketing\FaqController;
use App\Http\Controllers\Api\V1\Marketing\FeatureController;
use App\Http\Controllers\Api\V1\Marketing\HelpCenterController as MarketingHelpCenterController;
use App\Http\Controllers\Api\V1\Marketing\NewsletterArticleController as MarketingNewsletterArticleController;
use App\Http\Controllers\Api\V1\Marketing\NewsletterController;
use App\Http\Controllers\Api\V1\Marketing\PricingController;
use App\Http\Controllers\Api\V1\Marketing\WaitlistController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationDeviceController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\Payments\MerchantPaymentController;
use App\Http\Controllers\Api\V1\Payments\PaymentConnectionController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiActivityLogController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiAdminOverviewController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiAdminSettingsController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiModelController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiPromptTemplateController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiProviderController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiProviderCredentialController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiTaskController;
use App\Http\Controllers\Api\V1\Platform\Ai\AiUsageController;
use App\Http\Controllers\Api\V1\Platform\AutomationTemplateController as PlatformAutomationTemplateController;
use App\Http\Controllers\Api\V1\Platform\BlogContentImageController;
use App\Http\Controllers\Api\V1\Platform\ContactMessageController;
use App\Http\Controllers\Api\V1\Platform\ContentPageController as PlatformContentPageController;
use App\Http\Controllers\Api\V1\Platform\EmailTemplateController;
use App\Http\Controllers\Api\V1\Platform\FaqEntryController;
use App\Http\Controllers\Api\V1\Platform\HelpAnalyticsController;
use App\Http\Controllers\Api\V1\Platform\HelpArticleController as PlatformHelpArticleController;
use App\Http\Controllers\Api\V1\Platform\HelpCategoryController as PlatformHelpCategoryController;
use App\Http\Controllers\Api\V1\Platform\HelpContentImageController;
use App\Http\Controllers\Api\V1\Platform\HelpFeedbackController;
use App\Http\Controllers\Api\V1\Platform\HelpTagController;
use App\Http\Controllers\Api\V1\Platform\IntegrationController as PlatformIntegrationController;
use App\Http\Controllers\Api\V1\Platform\NewsletterController as PlatformNewsletterController;
use App\Http\Controllers\Api\V1\Platform\NewsletterSubscriberController;
use App\Http\Controllers\Api\V1\Platform\NotificationCampaignController;
use App\Http\Controllers\Api\V1\Platform\NotificationDeliveryController;
use App\Http\Controllers\Api\V1\Platform\PaymentCountryController;
use App\Http\Controllers\Api\V1\Platform\PaymentMethodController;
use App\Http\Controllers\Api\V1\Platform\PaymentProviderController;
use App\Http\Controllers\Api\V1\Platform\PaymentProviderCountryController;
use App\Http\Controllers\Api\V1\Platform\PaymentProviderMethodController;
use App\Http\Controllers\Api\V1\Platform\PaymentWebhookEventController;
use App\Http\Controllers\Api\V1\Platform\PlatformDashboardController;
use App\Http\Controllers\Api\V1\Platform\PlatformFeatureFlagController;
use App\Http\Controllers\Api\V1\Platform\PlatformInsightsController;
use App\Http\Controllers\Api\V1\Platform\PlatformIntegrationHealthController;
use App\Http\Controllers\Api\V1\Platform\PlatformSettingController;
use App\Http\Controllers\Api\V1\Platform\PlatformSettingHistoryController;
use App\Http\Controllers\Api\V1\Platform\PlatformSystemController;
use App\Http\Controllers\Api\V1\Platform\PricingPlanController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\Platform\UserController as PlatformUserController;
use App\Http\Controllers\Api\V1\Platform\WaitlistEntryController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProfileOnboardingController;
use App\Http\Controllers\Api\V1\ProfileSecurityController;
use App\Http\Controllers\Api\V1\ProfileSessionController;
use App\Http\Controllers\Api\V1\ProfileTwoFactorController;
use App\Http\Controllers\Api\V1\ProfitIntelligenceController;
use App\Http\Controllers\Api\V1\RecoveryOpportunityController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StoreCurrencyController;
use App\Http\Controllers\Api\V1\Storefront\PaymentSettingsController;
use App\Http\Controllers\Api\V1\Storefront\PublicStorefrontController;
use App\Http\Controllers\Api\V1\Storefront\ShippingZoneController;
use App\Http\Controllers\Api\V1\Storefront\StorefrontCheckoutController;
use App\Http\Controllers\Api\V1\Storefront\StorefrontSettingsController;
use App\Http\Controllers\Api\V1\Tenant\Ai\TenantAiCredentialController;
use App\Http\Controllers\Api\V1\Tenant\Ai\TenantAiSettingsController;
use App\Http\Controllers\Api\V1\Tenant\Ai\TenantAiUsageController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\Translation\TranslationController;
use App\Http\Controllers\Api\V1\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\UnsubscribeController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VoiceMessageController;
use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
use App\Http\Controllers\Api\V1\WhatsappAccountController;
use App\Http\Controllers\Api\Webhook\ChannelWebhookController;
use App\Http\Controllers\Api\Webhook\EmailWebhookController;
use App\Http\Controllers\Api\Webhook\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Versioned API surface (v1)
|--------------------------------------------------------------------------
|
| Three tiers of access, from widest to narrowest:
|
|   public     no authentication at all (provider webhooks, marketing site)
|   platform   any authenticated holder of a platform role
|   tenant     any member of the tenant resolved by the `tenant` middleware
|
| The `tenant` middleware resolves X-Tenant from the caller's own memberships, so
| a forged header cannot widen access. Permissions are enforced by
| `permission:<slug>` on each route, using the slugs from
| {@see \App\Support\PermissionRegistry}; keeping them here makes the required
| permission of every endpoint reviewable in one file instead of scattered
| through controllers. Write actions use the narrowest permission that still
| covers them (products.create to add, products.update to edit, products.delete
| to archive), so a limited role can be granted one without the others.
|
*/

/*
 * Fortify's own route file, required under /api/v1/auth.
 *
 * `Fortify::ignoreRoutes()` in FortifyServiceProvider::register() stops the
 * package from registering these itself, so this require is the only
 * registration. Every path inside the vendor file is relative to the group, and
 * `routes/api.php` already supplies /api/v1, so no prefix is applied twice; the
 * `fortify.prefix` config is therefore unused and kept empty to make that clear.
 *
 * The `web` middleware applied inside the vendor file is deliberate: the SPA
 * authenticates with a session cookie through Sanctum's stateful middleware, and
 * CSRF protection belongs to that flow.
 *
 * Fortify only emits the register routes when Features::registration() is
 * enabled; Dukavo leaves it off (see config/fortify.php), so the public
 * registration endpoint and its dedicated throttle are intentionally absent.
 */
Route::prefix('auth')->group(function (): void {
    require base_path('vendor/laravel/fortify/routes/routes.php');
});

// Dukavo's own sign-out replaces Fortify's for the same URI: Fortify's is bound
// to the session guard, so a SPA holding only a bearer token would be refused
// instead of signed out. Laravel indexes routes by method and URI, so the later
// registration is the one that answers — which is why this sits below the
// `require` above rather than above it.
//
// The `web` group is deliberate: ending a cookie session requires the session to
// be loaded, and an api-group request has none. It also matches the auth routes
// above, so the client sends the same CSRF header for both.
Route::middleware(['web', 'auth:sanctum'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.logout');
});

/*
 * The second step of a two-factor login.
 *
 * It keeps the `web` middleware of the Fortify flow so the browser client sends
 * the same CSRF header and cookie as it does for `/auth/login`. The route is
 * throttled by challenge token and IP; the challenge itself is also single-use
 * and attempt-limited in the service, so rate limiting is one layer, not the
 * only one.
 */
Route::middleware(['web', 'throttle:two-factor-challenge'])->group(function (): void {
    Route::post('auth/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->name('api.two-factor-challenge');
});

// Provider callbacks. Meta cannot present a Sanctum token, so these routes stay
// outside the authenticated group. The handler resolves the tenant from the
// connected account rather than trusting the payload.
Route::prefix('webhooks')->group(function (): void {
    Route::get('whatsapp', [WhatsappWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');
    Route::post('whatsapp', [WhatsappWebhookController::class, 'events'])
        ->name('webhooks.whatsapp.events');

    // Transactional email delivery receipts. Signed with a shared secret, and
    // closed when no secret is configured.
    Route::post('email', EmailWebhookController::class)
        ->name('webhooks.email');

    /*
     * The generic channel webhook. One endpoint serves every connector in the
     * registry; the connector verifies its own signature and translates its own
     * payload. The tenant is derived from the verified connection, never from
     * the payload, and the optional connection id in the path makes the URL
     * stable for providers that cannot send a tenant hint (Telegram, email,
     * SMS). Meta-backed channels may omit it and resolve by account id.
     */
    Route::get('channels/{channel}/{connection?}', [ChannelWebhookController::class, 'verify'])
        ->whereNumber('connection')
        ->name('webhooks.channels.verify');
    Route::post('channels/{channel}/{connection?}', [ChannelWebhookController::class, 'events'])
        ->whereNumber('connection')
        ->middleware('throttle:channel-webhook')
        ->name('webhooks.channels.events');

    /*
     * Payment provider callbacks. The provider cannot present Sanctum or a
     * tenant header, so the handler identifies the attempt from the signed
     * payload and verifies the signature with that merchant's own connection.
     * The dedicated limiter keeps an anonymous flood from reaching the HMAC
     * code path at unbounded rate.
     */
    Route::post('payments/{provider}', PaymentWebhookController::class)
        ->middleware('throttle:payment-webhook')
        ->name('webhooks.payments');
});

/*
 * Marketing unsubscribe links. The token is the authority: no session, no
 * Sanctum, no tenant. `GET` only reports what the link would do; `POST` applies
 * the opt-out and is also the RFC 8058 one-click target advertised in the
 * `List-Unsubscribe-Post` header. The named limiter keeps an anonymous client
 * from probing token values.
 */
Route::prefix('unsubscribe')->name('api.unsubscribe.')->middleware('throttle:unsubscribe')->group(function (): void {
    Route::get('{token}', [UnsubscribeController::class, 'show'])->name('show');
    Route::post('{token}', [UnsubscribeController::class, 'store'])->name('store');
});

/*
 * Marketing surface of dukavo.com: the pages a visitor sees before they ever
 * have an account. Unauthenticated by definition, and the two write endpoints
 * carry named per-IP throttle limiters (defined in AppServiceProvider) because
 * an anonymous form is exactly where abuse arrives first.
 *
 * These are configuration reads (`features`) and platform-level content reads
 * (`pricing`, `faq`) plus the lead-capture forms (`waitlist`, `contact`,
 * `newsletter`) — no tenant data exists anywhere in this group, so no
 * `tenant` middleware is involved, and none of it may ever gain one.
 */
Route::prefix('public')->name('api.public.')->group(function (): void {
    Route::get('features', FeatureController::class)->name('features.index');
    Route::get('pricing', [PricingController::class, 'index'])->name('pricing.index');
    Route::get('faq', [FaqController::class, 'index'])->name('faq.index');
    // Long-form pages (terms, privacy, about) addressed by their stable key.
    // An unpublished page is a 404 here, never a blank document.
    Route::get('pages/{key}', [MarketingContentPageController::class, 'show'])->name('pages.show');
    // The public newsletter archive: published editions only, newest first.
    Route::get('newsletters', [MarketingNewsletterArticleController::class, 'index'])->name('newsletters.index');
    Route::get('newsletters/{slug}', [MarketingNewsletterArticleController::class, 'show'])->name('newsletters.show');
    Route::post('waitlist', [WaitlistController::class, 'store'])->middleware('throttle:waitlist')->name('waitlist.store');
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:contact')->name('contact.store');
    Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:newsletter')->name('newsletter.subscribe');
    Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:newsletter')->name('newsletter.unsubscribe');

    /*
     * The public Help Center: Dukavo's own product documentation, anonymous
     * edition. Visibility is computed server-side from the viewer — a guest
     * here, so only `public` articles are ever reachable — never from the
     * query string. The signed-in edition lives in the `help` group below and
     * serves the same slugs.
     */
    Route::get('help/categories', [MarketingHelpCenterController::class, 'categories'])->name('help.categories.index');
    Route::get('help/articles', [MarketingHelpCenterController::class, 'articles'])->name('help.articles.index');
    Route::get('help/search', [MarketingHelpCenterController::class, 'search'])
        ->middleware('throttle:help-search')->name('help.search');
    Route::get('help/articles/{slug}', [MarketingHelpCenterController::class, 'article'])->name('help.articles.show');
    Route::post('help/articles/{slug}/feedback', [MarketingHelpCenterController::class, 'feedback'])
        ->middleware('throttle:help-feedback')->name('help.articles.feedback');

    /*
     * Merchant storefronts are public by design, but their data is still
     * tenant-owned. The tenant comes from the URL slug, never a header or
     * payload, and each controller enters TenantContext::run() before touching
     * a scoped model. Checkout has its own tighter limiter because it reserves
     * stock and writes an order.
     */
    Route::prefix('storefront/{tenantSlug}/{storeSlug}')
        ->name('storefront.')
        ->middleware('throttle:storefront')
        ->group(function (): void {
            Route::get('/', [PublicStorefrontController::class, 'show'])->name('show');
            Route::get('categories', [PublicStorefrontController::class, 'categories'])->name('categories.index');
            Route::get('products', [PublicStorefrontController::class, 'products'])->name('products.index');
            Route::get('products/{productSlug}', [PublicStorefrontController::class, 'product'])->name('products.show');
            Route::get('shipping-zones', [PublicStorefrontController::class, 'shippingZones'])->name('shipping-zones.index');
            Route::get('payment-methods', [PublicStorefrontController::class, 'paymentMethods'])->name('payment-methods.index');

            Route::post('checkout', [StorefrontCheckoutController::class, 'store'])
                ->middleware('throttle:storefront-checkout')->name('checkout.store');
            Route::get('orders/{token}', [StorefrontCheckoutController::class, 'show'])->name('orders.show');
            // Polling reads database state only; it never multiplies provider
            // traffic. Starting another attempt has its own tighter limiter.
            Route::get('orders/{token}/payment', [StorefrontCheckoutController::class, 'show'])
                ->name('orders.payment.show');
            Route::post('orders/{token}/payment', [StorefrontCheckoutController::class, 'retryPayment'])
                ->middleware('throttle:storefront-payment')
                ->name('orders.payment.retry');
        });
});

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->middleware('tenant.optional')->name('api.me');
    Route::get('permissions', [PermissionController::class, 'index'])->name('api.permissions.index');

    /*
     * Currency metadata for personal surfaces (profile settings, platform
     * screens). It is not tenant-scoped: a user's preferred display currency is
     * an account preference, not a workspace setting.
     */
    Route::get('currencies', [CurrencyController::class, 'index'])->name('api.currencies.index');

    /*
     * The Help Center, signed-in edition. Authenticated and active but
     * deliberately *outside* the `tenant` group: help articles document
     * Dukavo itself, not one workspace, so every reader sees the same
     * catalogue regardless of the selected tenant. Visibility is computed
     * server-side from the viewer — a merchant member sees merchant-only
     * guides, a platform operator sees everything — and a restricted article
     * answers 404, never 403.
     */
    Route::prefix('help')->name('api.help.')->group(function (): void {
        Route::get('categories', [AuthenticatedHelpCenterController::class, 'categories'])->name('categories.index');
        Route::get('articles', [AuthenticatedHelpCenterController::class, 'articles'])->name('articles.index');
        Route::get('search', [AuthenticatedHelpCenterController::class, 'search'])
            ->middleware('throttle:help-search')->name('search');
        Route::get('articles/{slug}', [AuthenticatedHelpCenterController::class, 'article'])->name('articles.show');
        Route::post('articles/{slug}/feedback', [AuthenticatedHelpCenterController::class, 'feedback'])
            ->middleware('throttle:help-feedback')->name('articles.feedback');
    });

    /*
     * Profile: the signed-in user's own account. Authenticated and active, but
     * deliberately *outside* the `tenant` group — the account belongs to the
     * person, not to whichever workspace happens to be selected, and the same
     * profile (avatar, password, devices) is served to every role and every
     * tenant. These routes always act on `$request->user()`, never on an id from
     * the path.
     */
    Route::prefix('profile')->name('api.profile.')->group(function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');
        Route::get('overview', [ProfileController::class, 'overview'])->name('overview');
        Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
        Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
        Route::put('password', [ProfileController::class, 'updatePassword'])->name('password.update');

        /*
         * First-login preferences. `skip` is a first-class outcome: the flow is
         * never a gate, and either completion path stamps the same one-shot
         * marker so the prompt cannot return.
         */
        Route::get('onboarding', [ProfileOnboardingController::class, 'show'])->name('onboarding.show');
        Route::post('onboarding', [ProfileOnboardingController::class, 'store'])->name('onboarding.store');
        Route::post('onboarding/skip', [ProfileOnboardingController::class, 'skip'])->name('onboarding.skip');

        /*
         * Country, city and timezone data for the personal selectors. Not
         * tenant-scoped: a person's location is theirs, not a store's.
         */
        Route::get('locations/countries', [LocationController::class, 'countries'])->name('locations.countries');
        Route::get('locations/countries/{country}/cities', [LocationController::class, 'cities'])->name('locations.cities');
        Route::get('locations/timezones', [LocationController::class, 'timezones'])->name('locations.timezones');

        /*
         * Signed-in devices. `sessions/{token}` must stay below the bare
         * `sessions` route so `DELETE /profile/sessions` matches the "sign out
         * every other device" action and never parses as an id.
         */
        Route::get('sessions', [ProfileSessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions', [ProfileSessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('sessions/{token}', [ProfileSessionController::class, 'destroy'])->name('sessions.destroy');

        /*
         * The account's own security history. Append-only; no route edits or
         * deletes an event.
         */
        Route::get('security/activity', [ProfileSecurityController::class, 'activity'])->name('security.activity');

        /*
         * TOTP enrolment and recovery codes. The secret appears only in the
         * setup response and the codes only in the confirm/regenerate
         * responses; neither is ever readable afterwards.
         */
        Route::get('two-factor', [ProfileTwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('two-factor/setup', [ProfileTwoFactorController::class, 'setup'])->name('two-factor.setup');
        Route::post('two-factor/confirm', [ProfileTwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::post('two-factor/recovery-codes', [ProfileTwoFactorController::class, 'regenerate'])->name('two-factor.recovery-codes');
        Route::delete('two-factor', [ProfileTwoFactorController::class, 'destroy'])->name('two-factor.destroy');

        Route::middleware('tenant.optional')->group(function (): void {
            Route::get('notifications/channels', [NotificationPreferenceController::class, 'channels'])->name('notifications.channels');
            Route::get('notifications', [NotificationPreferenceController::class, 'show'])->name('notifications.show');
            Route::put('notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');
        });

        /*
         * Push installations. A user may have several; each token belongs to
         * one phone and is revoked on logout or when Firebase rejects it.
         * `unregister` accepts the token itself so logout can clean up even
         * when the app no longer remembers the numeric id.
         */
        Route::prefix('devices')->name('devices.')->group(function (): void {
            Route::get('/', [NotificationDeviceController::class, 'index'])->name('index');
            Route::post('/', [NotificationDeviceController::class, 'store'])
                ->middleware('throttle:notification-devices')->name('store');
            Route::post('unregister', [NotificationDeviceController::class, 'unregister'])->name('unregister');
            Route::patch('{device}', [NotificationDeviceController::class, 'update'])->name('update');
            Route::delete('{device}', [NotificationDeviceController::class, 'destroy'])->name('destroy');
        });
    });

    /*
     * Notification centre. Personal to the signed-in account, so — like the
     * profile above — these sit outside the `tenant` group: a multi-tenant user
     * has one bell across every workspace. The action routes precede the bare
     * `{notification}` route so "read-all" and "unread-count" are never parsed
     * as an id.
     */
    Route::prefix('notifications')->name('api.notifications.')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::get('{notification}', [NotificationController::class, 'show'])->name('show');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
        Route::post('{notification}/unread', [NotificationController::class, 'unread'])->name('unread');
        Route::delete('{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    /*
     * Onboarding: the workspace a fresh account creates before it has one.
     * Authenticated and active, but deliberately *outside* the `tenant` group
     * below — there is no tenant to resolve yet, which is the entire point.
     * The request may describe the workspace; the role assignment inside
     * TenantService::provisionFor() is not request input.
     */
    Route::post('onboarding/tenant', [OnboardingController::class, 'storeTenant'])->name('api.onboarding.tenant');

    /*
     * Platform administration: tenants themselves. No tenant context is resolved
     * here, so these queries read across tenants on purpose.
     */
    Route::prefix('platform')->name('api.platform.')->middleware('platform')->group(function (): void {
        /*
         * The platform overview. Aggregates Dukavo's own tables (tenants, users,
         * leads) and never resolves a tenant, so it is honest about operating at
         * the platform level rather than inside a merchant workspace.
         */
        Route::get('dashboard', [PlatformDashboardController::class, 'index'])->name('dashboard');

        /*
         * Platform configuration. The registry is the allow-list: a key that is
         * not defined there cannot be read or written through these routes.
         * Reading the section catalogue and the last health result is a view
         * permission; changing a value is section-specific, and replacing a
         * secret additionally needs `platform_settings.secrets`. Static routes
         * are declared before `{category}` so they cannot be captured as a
         * category name.
         */
        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/', [PlatformSettingController::class, 'index'])
                ->middleware('permission:platform_settings.view,platform_security.view')
                ->name('index');
            Route::get('system', [PlatformSystemController::class, 'index'])
                ->middleware('permission:platform_settings.view,platform_security.view')
                ->name('system');
            Route::get('history', [PlatformSettingHistoryController::class, 'index'])
                ->middleware('permission:platform_settings.view')
                ->name('history');
            Route::get('health', [PlatformIntegrationHealthController::class, 'index'])
                ->middleware('permission:platform_settings.view,integrations_admin.view')
                ->name('health.index');
            Route::post('health/{integration}', [PlatformIntegrationHealthController::class, 'check'])
                ->middleware('permission:platform_settings.test,integrations_admin.manage')
                ->name('health.check');
            Route::get('features', [PlatformFeatureFlagController::class, 'index'])
                ->middleware('permission:platform_features.view,platform_settings.view')
                ->name('features.index');
            Route::put('features/{flag}', [PlatformFeatureFlagController::class, 'update'])
                ->middleware('permission:platform_features.manage')
                ->name('features.update');
            Route::get('{category}', [PlatformSettingController::class, 'show'])
                ->middleware('permission:platform_settings.view,platform_security.view')
                ->name('show');
            Route::put('{category}', [PlatformSettingController::class, 'update'])
                ->middleware('permission:platform_settings.update,platform_security.manage,platform_features.manage')
                ->name('update');
        });

        /*
         * AI assistance for Dukavo staff. Platform-level because both endpoints
         * work on Dukavo's own data (the cross-tenant overview and the marketing
         * newsletters), never a merchant workspace. `permission:ai.use` is held by
         * Super Admin and Dukavo Admin, and can be revoked per role.
         */
        Route::get('ai/availability', [AiAvailabilityController::class, 'index'])
            ->middleware('permission:ai.use')->name('ai.availability');
        Route::get('ai/insights', [PlatformInsightsController::class, 'index'])
            ->middleware('permission:ai.use')->name('ai.insights');
        Route::post('ai/newsletter-content', [ContentAssistantController::class, 'generate'])
            ->middleware('permission:ai.use')->name('ai.newsletter-content');

        /*
         * Translation review for Dukavo's own marketing content (FAQ, blog,
         * content pages). Platform content has no tenant, so these rows are the
         * tenant-less half of the same review ledger the merchant surface uses.
         * Generation is a proposal; only an explicit approve publishes it.
         */
        Route::prefix('translations')->name('translations.')->group(function (): void {
            Route::get('entities', [TranslationController::class, 'entities'])
                ->middleware('permission:translations.view')->name('entities');
            Route::get('usage', [TranslationController::class, 'usage'])
                ->middleware('permission:translations.view')->name('usage');
            Route::get('{entity}/{id}', [TranslationController::class, 'show'])
                ->middleware('permission:translations.view')->name('show');
            Route::post('{entity}/{id}/generate', [TranslationController::class, 'generate'])
                ->middleware('permission:translations.generate')->name('generate');
            Route::put('{entity}/{id}', [TranslationController::class, 'save'])
                ->middleware('permission:translations.review')->name('save');
            Route::post('{entity}/{id}/approve-all', [TranslationController::class, 'approveAll'])
                ->middleware('permission:translations.review')->name('approve-all');
            Route::patch('{translation}', [TranslationController::class, 'update'])
                ->middleware('permission:translations.review')->name('update');
            Route::post('{translation}/approve', [TranslationController::class, 'approve'])
                ->middleware('permission:translations.review')->name('approve');
            Route::post('{translation}/reject', [TranslationController::class, 'reject'])
                ->middleware('permission:translations.review')->name('reject');
            Route::post('{translation}/regenerate', [TranslationController::class, 'regenerate'])
                ->middleware('permission:translations.review')->name('regenerate');
        });

        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
        Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
        Route::get('tenants/{tenant}/members', [TenantController::class, 'members'])->name('tenants.members');

        /*
         * Roles & Permissions. The same controller the tenant group uses: inside
         * the platform group there is no tenant context, so the index shows the
         * whole catalogue and RoleController's guards ensure platform scoped
         * roles can only be written by platform operators.
         */
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage')->name('roles.update');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.manage')->name('roles.permissions');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage')->name('roles.destroy');

        /*
         * Dukavo's own staff. Users who hold a platform role assignment; tenant
         * members are managed through the tenant team endpoints instead.
         */
        Route::get('users', [PlatformUserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::get('users/{user}', [PlatformUserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::post('users', [PlatformUserController::class, 'store'])->middleware('permission:users.manage')->name('users.store');
        Route::patch('users/{user}', [PlatformUserController::class, 'update'])->middleware('permission:users.manage')->name('users.update');
        Route::post('users/{user}/roles/{role}', [PlatformUserController::class, 'assignRole'])->middleware('permission:users.manage')->name('users.roles.assign');
        Route::delete('users/{user}/roles/{role}', [PlatformUserController::class, 'revokeRole'])->middleware('permission:users.manage')->name('users.roles.revoke');

        /*
         * The platform audit trail. `audit_logs` is shared: a platform operator
         * reads both the tenant-less platform events and tenant events, while a
         * tenant user is restricted to its own tenant's rows (AuditLogController
         * splits the two paths).
         */
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
        Route::get('audit-logs/actions', [AuditLogController::class, 'actions'])->middleware('permission:audit.view')->name('audit.actions');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit.view')->name('audit.show');

        /*
         * Platform announcements. The only surface that can write a notification
         * to every merchant at once, so it is doubly gated: platform operator
         * plus an explicit notifications permission. `audience-count` and
         * `preview` precede the bound `{campaign}` routes so they are never
         * parsed as an id. Merchants have no route into this group at all.
         */
        Route::prefix('notification-campaigns')->name('notification-campaigns.')->group(function (): void {
            Route::get('/', [NotificationCampaignController::class, 'index'])
                ->middleware('permission:notifications.view')->name('index');
            Route::post('/', [NotificationCampaignController::class, 'store'])
                ->middleware('permission:notifications.manage')->name('store');
            Route::post('preview', [NotificationCampaignController::class, 'preview'])
                ->middleware('permission:notifications.view')->name('preview');
            Route::post('audience-count', [NotificationCampaignController::class, 'audienceCount'])
                ->middleware('permission:notifications.view')->name('audience-count');
            Route::get('{campaign}', [NotificationCampaignController::class, 'show'])
                ->middleware('permission:notifications.view')->name('show');
            Route::patch('{campaign}', [NotificationCampaignController::class, 'update'])
                ->middleware('permission:notifications.manage')->name('update');
            Route::delete('{campaign}', [NotificationCampaignController::class, 'destroy'])
                ->middleware('permission:notifications.manage')->name('destroy');
            Route::post('{campaign}/schedule', [NotificationCampaignController::class, 'schedule'])
                ->middleware('permission:notifications.manage')->name('schedule');
            Route::post('{campaign}/publish', [NotificationCampaignController::class, 'publish'])
                ->middleware('permission:notifications.manage')->name('publish');
            Route::post('{campaign}/cancel', [NotificationCampaignController::class, 'cancel'])
                ->middleware('permission:notifications.manage')->name('cancel');
            Route::get('{campaign}/recipients', [NotificationCampaignController::class, 'recipients'])
                ->middleware('permission:notifications.view')->name('recipients');
            Route::get('{campaign}/stats', [NotificationCampaignController::class, 'stats'])
                ->middleware('permission:notifications.view')->name('stats');
        });

        /*
         * Email template administration. The catalogue lives in code; an
         * operator may only change plain-text subject/heading/intro per locale,
         * disable an optional template, preview both directions, send a test to
         * themselves, or restore the shipped copy. Security and account mail is
         * mandatory and cannot be disabled. The template key contains dots
         * (`tenant.order.created`), which is a valid route segment.
         */
        Route::prefix('email-templates')->name('email-templates.')->group(function (): void {
            Route::get('/', [EmailTemplateController::class, 'index'])
                ->middleware('permission:notifications.view')->name('index');
            Route::get('{template}', [EmailTemplateController::class, 'show'])
                ->middleware('permission:notifications.view')->name('show');
            Route::patch('{template}', [EmailTemplateController::class, 'update'])
                ->middleware('permission:notifications.manage')->name('update');
            Route::post('{template}/restore', [EmailTemplateController::class, 'restore'])
                ->middleware('permission:notifications.manage')->name('restore');
            Route::post('{template}/preview', [EmailTemplateController::class, 'preview'])
                ->middleware('permission:notifications.view')->name('preview');
            Route::post('{template}/test', [EmailTemplateController::class, 'test'])
                ->middleware('permission:notifications.manage')->name('test');
        });

        /*
         * Delivery monitoring. Read-only visibility into every channel attempt,
         * across tenants, with the private message payload intentionally absent.
         * A retry is a separate, audited action from a recorded transient
         * failure only; it is not a way to rewrite a provider's answer.
         */
        Route::prefix('notification-deliveries')->name('notification-deliveries.')->group(function (): void {
            Route::get('/', [NotificationDeliveryController::class, 'index'])
                ->middleware('permission:notifications.view')->name('index');
            Route::get('{delivery}', [NotificationDeliveryController::class, 'show'])
                ->middleware('permission:notifications.view')->name('show');
            Route::post('{delivery}/retry', [NotificationDeliveryController::class, 'retry'])
                ->middleware('permission:notifications.manage')->name('retry');
        });

        /*
         * Platform automation templates. A template is a global blueprint that
         * merchants can install; publishing a new version never rewrites an
         * existing installation. `automation.manage` is the same capability a
         * merchant manager holds, but this group is behind `platform`, so a
         * tenant token can never author the catalogue.
         */
        Route::prefix('automation-templates')->name('automation-templates.')->group(function (): void {
            Route::get('/', [PlatformAutomationTemplateController::class, 'index'])
                ->middleware('permission:automation.view')->name('index');
            Route::get('meta', [PlatformAutomationTemplateController::class, 'meta'])
                ->middleware('permission:automation.view')->name('meta');
            Route::post('/', [PlatformAutomationTemplateController::class, 'store'])
                ->middleware('permission:automation.manage')->name('store');
            Route::get('{automationTemplate}', [PlatformAutomationTemplateController::class, 'show'])
                ->middleware('permission:automation.view')->name('show');
            Route::patch('{automationTemplate}', [PlatformAutomationTemplateController::class, 'update'])
                ->middleware('permission:automation.manage')->name('update');
            Route::delete('{automationTemplate}', [PlatformAutomationTemplateController::class, 'destroy'])
                ->middleware('permission:automation.manage')->name('destroy');
            Route::post('{automationTemplate}/publish', [PlatformAutomationTemplateController::class, 'publish'])
                ->middleware('permission:automation.manage')->name('publish');
            Route::post('{automationTemplate}/archive', [PlatformAutomationTemplateController::class, 'archive'])
                ->middleware('permission:automation.manage')->name('archive');
            Route::post('{automationTemplate}/restore', [PlatformAutomationTemplateController::class, 'restore'])
                ->middleware('permission:automation.manage')->name('restore');
            Route::get('{automationTemplate}/versions', [PlatformAutomationTemplateController::class, 'versions'])
                ->middleware('permission:automation.view')->name('versions');
        });

        /*
         * Marketing administration: pricing, FAQ, waitlist and contact inbox.
         * Platform-level by design (these tables carry no tenant_id), so no
         * `permission:` middleware is layered on top of `platform` — the same
         * choice as tenants above. Adding a permission slug here would also
         * auto-grant it to every merchant through PermissionRegistry's
         * platform role seeding, which would be wrong: this is Dukavo's own
         * content, not a merchant's.
         */
        Route::get('pricing-plans', [PricingPlanController::class, 'index'])->name('pricing-plans.index');
        Route::post('pricing-plans', [PricingPlanController::class, 'store'])->name('pricing-plans.store');
        Route::get('pricing-plans/{pricingPlan}', [PricingPlanController::class, 'show'])->name('pricing-plans.show');
        Route::patch('pricing-plans/{pricingPlan}', [PricingPlanController::class, 'update'])->name('pricing-plans.update');
        Route::delete('pricing-plans/{pricingPlan}', [PricingPlanController::class, 'destroy'])->name('pricing-plans.destroy');

        Route::get('faq-entries', [FaqEntryController::class, 'index'])->name('faq-entries.index');
        Route::post('faq-entries', [FaqEntryController::class, 'store'])->name('faq-entries.store');
        Route::get('faq-entries/{faqEntry}', [FaqEntryController::class, 'show'])->name('faq-entries.show');
        Route::patch('faq-entries/{faqEntry}', [FaqEntryController::class, 'update'])->name('faq-entries.update');
        Route::delete('faq-entries/{faqEntry}', [FaqEntryController::class, 'destroy'])->name('faq-entries.destroy');

        /*
         * The public content pages (terms, privacy, about). No create and no
         * delete: the pages are seeded and fixed, so the platform team only
         * reads and replaces their content.
         */
        Route::get('content-pages', [PlatformContentPageController::class, 'index'])->name('content-pages.index');
        Route::get('content-pages/{contentPage}', [PlatformContentPageController::class, 'show'])->name('content-pages.show');
        Route::patch('content-pages/{contentPage}', [PlatformContentPageController::class, 'update'])->name('content-pages.update');

        Route::get('waitlist-entries', [WaitlistEntryController::class, 'index'])->name('waitlist-entries.index');
        Route::patch('waitlist-entries/{waitlistEntry}', [WaitlistEntryController::class, 'update'])->name('waitlist-entries.update');
        Route::delete('waitlist-entries/{waitlistEntry}', [WaitlistEntryController::class, 'destroy'])->name('waitlist-entries.destroy');

        Route::get('contact-messages', [ContactMessageController::class, 'index'])->name('contact-messages.index');
        Route::patch('contact-messages/{contactMessage}', [ContactMessageController::class, 'update'])->name('contact-messages.update');
        Route::delete('contact-messages/{contactMessage}', [ContactMessageController::class, 'destroy'])->name('contact-messages.destroy');

        Route::get('newsletter-subscribers', [NewsletterSubscriberController::class, 'index'])->name('newsletter-subscribers.index');
        Route::patch('newsletter-subscribers/{newsletterSubscriber}', [NewsletterSubscriberController::class, 'update'])->name('newsletter-subscribers.update');
        Route::delete('newsletter-subscribers/{newsletterSubscriber}', [NewsletterSubscriberController::class, 'destroy'])->name('newsletter-subscribers.destroy');

        /*
         * Newsletter editions: the articles published to the public archive.
         * Distinct from the subscriber list above — this is the content, that is
         * the audience. `slug-availability` precedes `{newsletter}` so it is
         * never parsed as an id.
         */
        Route::get('newsletters', [PlatformNewsletterController::class, 'index'])->name('newsletters.index');
        Route::post('newsletters', [PlatformNewsletterController::class, 'store'])->name('newsletters.store');
        Route::post('newsletters/content-images', [BlogContentImageController::class, 'store'])->name('newsletters.content-images.store');
        Route::get('newsletters/slug-availability', [PlatformNewsletterController::class, 'slugAvailability'])->name('newsletters.slug-availability');
        Route::get('newsletters/{newsletter}', [PlatformNewsletterController::class, 'show'])->name('newsletters.show');
        Route::patch('newsletters/{newsletter}', [PlatformNewsletterController::class, 'update'])->name('newsletters.update');
        Route::delete('newsletters/{newsletter}', [PlatformNewsletterController::class, 'destroy'])->name('newsletters.destroy');

        /*
         * Help Center administration: Dukavo's own product documentation.
         * Platform-level by design (these tables carry no tenant_id), so — like
         * the FAQ and newsletter groups above — no `permission:` middleware is
         * layered on top of `platform`. Fixed action routes (`slug-availability`,
         * `content-images`, `reorder`, the editorial transitions) precede their
         * bound `{param}` siblings so a keyword is never parsed as an id.
         */
        Route::prefix('help')->name('help.')->group(function (): void {
            Route::get('categories', [PlatformHelpCategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [PlatformHelpCategoryController::class, 'store'])->name('categories.store');
            Route::get('categories/slug-availability', [PlatformHelpCategoryController::class, 'slugAvailability'])->name('categories.slug-availability');
            Route::post('categories/reorder', [PlatformHelpCategoryController::class, 'reorder'])->name('categories.reorder');
            Route::get('categories/{helpCategory}', [PlatformHelpCategoryController::class, 'show'])->name('categories.show');
            Route::patch('categories/{helpCategory}', [PlatformHelpCategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{helpCategory}', [PlatformHelpCategoryController::class, 'destroy'])->name('categories.destroy');

            Route::get('articles', [PlatformHelpArticleController::class, 'index'])->name('articles.index');
            Route::post('articles', [PlatformHelpArticleController::class, 'store'])->name('articles.store');
            Route::post('articles/content-images', [HelpContentImageController::class, 'store'])->name('articles.content-images.store');
            Route::get('articles/slug-availability', [PlatformHelpArticleController::class, 'slugAvailability'])->name('articles.slug-availability');
            // Editorial state changes are explicit actions so the audit trail
            // records the decision, not just a field edit.
            Route::post('articles/{helpArticle}/publish', [PlatformHelpArticleController::class, 'publish'])->name('articles.publish');
            Route::post('articles/{helpArticle}/unpublish', [PlatformHelpArticleController::class, 'unpublish'])->name('articles.unpublish');
            Route::post('articles/{helpArticle}/archive', [PlatformHelpArticleController::class, 'archive'])->name('articles.archive');
            Route::post('articles/{helpArticle}/restore', [PlatformHelpArticleController::class, 'restore'])->name('articles.restore');
            Route::get('articles/{helpArticle}', [PlatformHelpArticleController::class, 'show'])->name('articles.show');
            Route::patch('articles/{helpArticle}', [PlatformHelpArticleController::class, 'update'])->name('articles.update');
            Route::delete('articles/{helpArticle}', [PlatformHelpArticleController::class, 'destroy'])->name('articles.destroy');

            Route::get('tags', [HelpTagController::class, 'index'])->name('tags.index');
            Route::post('tags', [HelpTagController::class, 'store'])->name('tags.store');
            Route::get('tags/{helpTag}', [HelpTagController::class, 'show'])->name('tags.show');
            Route::patch('tags/{helpTag}', [HelpTagController::class, 'update'])->name('tags.update');
            Route::delete('tags/{helpTag}', [HelpTagController::class, 'destroy'])->name('tags.destroy');

            Route::get('feedback', [HelpFeedbackController::class, 'index'])->name('feedback.index');
            Route::get('feedback/comments', [HelpFeedbackController::class, 'comments'])->name('feedback.comments');
            Route::delete('feedback/{feedback}', [HelpFeedbackController::class, 'destroy'])->name('feedback.destroy');

            Route::get('analytics/overview', [HelpAnalyticsController::class, 'overview'])->name('analytics.overview');
            Route::get('analytics/searches', [HelpAnalyticsController::class, 'searches'])->name('analytics.searches');
            Route::get('analytics/helpfulness', [HelpAnalyticsController::class, 'helpfulness'])->name('analytics.helpfulness');
        });

        /*
         * Payment catalogue administration. Platform operators decide which
         * markets and providers are offered, and can inspect signed webhook
         * evidence. There is deliberately no route that reads a merchant
         * connection: credentials belong to the merchant and are write-only.
         */
        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::get('countries', [PaymentCountryController::class, 'index'])
                ->middleware('permission:platform_payments.view')->name('countries.index');
            Route::patch('countries/{country:code}', [PaymentCountryController::class, 'update'])
                ->middleware('permission:platform_payments.manage')->name('countries.update');

            Route::get('providers', [PaymentProviderController::class, 'index'])
                ->middleware('permission:platform_payments.view')->name('providers.index');
            Route::post('providers', [PaymentProviderController::class, 'store'])
                ->middleware('permission:platform_payments.manage')->name('providers.store');
            Route::patch('providers/{provider:code}', [PaymentProviderController::class, 'update'])
                ->middleware('permission:platform_payments.manage')->name('providers.update');

            Route::post('providers/{provider:code}/countries', [PaymentProviderCountryController::class, 'store'])
                ->middleware('permission:platform_payments.manage')->name('providers.countries.store');
            Route::patch('providers/{provider:code}/countries/{coverage}', [PaymentProviderCountryController::class, 'update'])
                ->middleware('permission:platform_payments.manage')->name('providers.countries.update');
            Route::delete('providers/{provider:code}/countries/{coverage}', [PaymentProviderCountryController::class, 'destroy'])
                ->middleware('permission:platform_payments.manage')->name('providers.countries.destroy');

            Route::post('providers/{provider:code}/methods', [PaymentProviderMethodController::class, 'store'])
                ->middleware('permission:platform_payments.manage')->name('providers.methods.store');
            Route::patch('providers/{provider:code}/methods/{providerMethod}', [PaymentProviderMethodController::class, 'update'])
                ->middleware('permission:platform_payments.manage')->name('providers.methods.update');
            Route::delete('providers/{provider:code}/methods/{providerMethod}', [PaymentProviderMethodController::class, 'destroy'])
                ->middleware('permission:platform_payments.manage')->name('providers.methods.destroy');

            Route::get('methods', [PaymentMethodController::class, 'index'])
                ->middleware('permission:platform_payments.view')->name('methods.index');
            Route::post('methods', [PaymentMethodController::class, 'store'])
                ->middleware('permission:platform_payments.manage')->name('methods.store');
            Route::patch('methods/{paymentMethod}', [PaymentMethodController::class, 'update'])
                ->middleware('permission:platform_payments.manage')->name('methods.update');
            Route::delete('methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])
                ->middleware('permission:platform_payments.manage')->name('methods.destroy');

            Route::get('webhook-events', [PaymentWebhookEventController::class, 'index'])
                ->middleware('permission:platform_payments.view')->name('webhook-events.index');
            Route::get('webhook-events/{event}', [PaymentWebhookEventController::class, 'show'])
                ->middleware('permission:platform_payments.view')->name('webhook-events.show');
        });

        /*
         * AI infrastructure administration. The platform owner's half of the
         * AI surface: providers, credentials, models, task routing, prompts,
         * usage and the attempt-level activity log. Strictly separate from the
         * staff AI assistance above (`ai/availability` &c., which is `ai.use`)
         * and from the merchant's own AI settings (the tenant group below).
         *
         * Reads need `ai_admin.view`; configuration `ai_admin.manage`; the
         * credential surface is the most sensitive and needs `ai_admin.rotate`
         * on top. No route here ever returns a key: credentials are write-only
         * and every read is masked.
         */
        Route::prefix('ai/admin')->name('ai-admin.')->group(function (): void {
            Route::get('overview', [AiAdminOverviewController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('overview');
            Route::get('settings', [AiAdminSettingsController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('settings');
            Route::get('usage', [AiUsageController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('usage');

            Route::get('activity-logs', [AiActivityLogController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('activity-logs.index');
            Route::get('activity-logs/categories', [AiActivityLogController::class, 'categories'])
                ->middleware('permission:ai_admin.view')->name('activity-logs.categories');

            Route::get('providers', [AiProviderController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('providers.index');
            // Registered before the bound `{provider}` so it is never parsed as an id.
            Route::get('providers/catalog', [AiProviderController::class, 'catalog'])
                ->middleware('permission:ai_admin.view')->name('providers.catalog');
            Route::get('providers/{provider}', [AiProviderController::class, 'show'])
                ->middleware('permission:ai_admin.view')->name('providers.show');
            Route::get('providers/{provider}/credentials', [AiProviderCredentialController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('providers.credentials.index');
            Route::get('providers/{provider}/models', [AiModelController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('providers.models.index');

            Route::get('tasks', [AiTaskController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('tasks.index');
            Route::get('tasks/{task}', [AiTaskController::class, 'show'])
                ->middleware('permission:ai_admin.view')->name('tasks.show');

            Route::get('prompts', [AiPromptTemplateController::class, 'index'])
                ->middleware('permission:ai_admin.view')->name('prompts.index');
            Route::get('prompts/{template}', [AiPromptTemplateController::class, 'show'])
                ->middleware('permission:ai_admin.view')->name('prompts.show');

            Route::middleware('permission:ai_admin.manage')->group(function (): void {
                Route::post('providers', [AiProviderController::class, 'store'])->name('providers.store');
                Route::patch('providers/{provider}', [AiProviderController::class, 'update'])->name('providers.update');

                Route::post('providers/{provider}/models', [AiModelController::class, 'store'])->name('providers.models.store');
                Route::patch('models/{model}', [AiModelController::class, 'update'])->name('models.update');
                Route::delete('models/{model}', [AiModelController::class, 'destroy'])->name('models.destroy');

                Route::post('tasks/seed', [AiTaskController::class, 'seed'])->name('tasks.seed');
                Route::patch('tasks/{task}', [AiTaskController::class, 'update'])->name('tasks.update');
                // The chain replaces the whole chain, so a task is never mid-edit.
                Route::put('tasks/{task}/routes', [AiTaskController::class, 'syncRoutes'])->name('tasks.routes');

                Route::post('prompts', [AiPromptTemplateController::class, 'store'])->name('prompts.store');
                Route::post('prompts/{template}/versions', [AiPromptTemplateController::class, 'storeVersion'])->name('prompts.versions.store');
                Route::post('prompts/{template}/versions/{version}/activate', [AiPromptTemplateController::class, 'activateVersion'])->name('prompts.versions.activate');
            });

            Route::middleware('permission:ai_admin.rotate')->group(function (): void {
                Route::post('providers/{provider}/credentials', [AiProviderCredentialController::class, 'store'])->name('providers.credentials.store');
                Route::delete('providers/{provider}/credentials/{credential}', [AiProviderCredentialController::class, 'revoke'])->name('providers.credentials.revoke');
                Route::post('providers/{provider}/test-connection', [AiProviderCredentialController::class, 'test'])->name('providers.test-connection');
            });
        });

        /*
         * Omnichannel integration health. Read-only apart from an explicit
         * re-check, which calls the provider and is therefore a manage action.
         * Credentials stay encrypted on the tenant connection; this surface
         * reports availability, status and failure text only.
         */
        Route::prefix('integrations')->name('integrations.')->group(function (): void {
            Route::get('/', [PlatformIntegrationController::class, 'index'])
                ->middleware('permission:integrations_admin.view')->name('index');
            Route::get('{channel}', [PlatformIntegrationController::class, 'show'])
                ->middleware('permission:integrations_admin.view')->name('show');
            Route::post('{channel}/recheck', [PlatformIntegrationController::class, 'recheck'])
                ->middleware('permission:integrations_admin.manage')->name('recheck');
        });
    });

    /*
     * Tenant administration and commerce. Every route below runs inside the
     * resolved tenant, so tenant scoping is inherited by the model queries rather
     * than being restated per controller.
     */
    Route::prefix('tenant')->name('api.tenant.')->middleware('tenant')->group(function (): void {
        // RBAC
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage')->name('roles.destroy');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.manage')->name('roles.permissions');
        Route::post('roles/{role}/assign', [RoleController::class, 'assign'])->middleware('permission:users.manage')->name('roles.assign');
        Route::post('roles/{role}/revoke', [RoleController::class, 'revoke'])->middleware('permission:users.manage')->name('roles.revoke');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.manage')->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage')->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.manage')->name('users.destroy');

        // Stores
        Route::get('stores', [StoreController::class, 'index'])->middleware('permission:stores.view')->name('stores.index');
        Route::post('stores', [StoreController::class, 'store'])->middleware('permission:stores.manage')->name('stores.store');
        Route::get('stores/{store}', [StoreController::class, 'show'])->middleware('permission:stores.view')->name('stores.show');
        Route::patch('stores/{store}', [StoreController::class, 'update'])->middleware('permission:stores.manage')->name('stores.update');
        Route::delete('stores/{store}', [StoreController::class, 'destroy'])->middleware('permission:stores.manage')->name('stores.destroy');
        Route::post('stores/{store}/default', [StoreController::class, 'makeDefault'])->middleware('permission:stores.manage')->name('stores.default');

        /*
         * Storefront, delivery and payment configuration is scoped to one store.
         * Reading the public shop's setup is a view grant; changing what
         * customers see or what they are charged for delivery is a manage grant.
         */
        Route::get('stores/{store}/storefront', [StorefrontSettingsController::class, 'show'])
            ->middleware('permission:storefront.view')->name('stores.storefront.show');
        Route::patch('stores/{store}/storefront', [StorefrontSettingsController::class, 'update'])
            ->middleware('permission:storefront.manage')->name('stores.storefront.update');

        Route::get('stores/{store}/shipping-zones', [ShippingZoneController::class, 'index'])
            ->middleware('permission:shipping.view')->name('stores.shipping-zones.index');
        Route::post('stores/{store}/shipping-zones', [ShippingZoneController::class, 'store'])
            ->middleware('permission:shipping.manage')->name('stores.shipping-zones.store');
        Route::patch('stores/{store}/shipping-zones/{shippingZone}', [ShippingZoneController::class, 'update'])
            ->middleware('permission:shipping.manage')->name('stores.shipping-zones.update');
        Route::delete('stores/{store}/shipping-zones/{shippingZone}', [ShippingZoneController::class, 'destroy'])
            ->middleware('permission:shipping.manage')->name('stores.shipping-zones.destroy');

        Route::get('stores/{store}/payments', [PaymentSettingsController::class, 'show'])
            ->middleware('permission:payments.view')->name('stores.payments.show');
        Route::patch('stores/{store}/payments', [PaymentSettingsController::class, 'update'])
            ->middleware('permission:payments.manage')->name('stores.payments.update');

        /*
         * Provider connections. The secret key is write-only; listing returns
         * only status and verification evidence. `scopeBindings()` makes a
         * connection from another store a 404 before the controller runs.
         */
        Route::get('stores/{store}/payment-connections', [PaymentConnectionController::class, 'index'])
            ->middleware('permission:payments.view')->name('stores.payment-connections.index');
        Route::post('stores/{store}/payment-connections', [PaymentConnectionController::class, 'store'])
            ->middleware('permission:payments.manage')->name('stores.payment-connections.store');
        Route::patch('stores/{store}/payment-connections/{account}', [PaymentConnectionController::class, 'update'])
            ->middleware('permission:payments.manage')->scopeBindings()->name('stores.payment-connections.update');
        Route::post('stores/{store}/payment-connections/{account}/verify', [PaymentConnectionController::class, 'verify'])
            ->middleware('permission:payments.manage')->scopeBindings()->name('stores.payment-connections.verify');
        Route::delete('stores/{store}/payment-connections/{account}', [PaymentConnectionController::class, 'destroy'])
            ->middleware('permission:payments.manage')->scopeBindings()->name('stores.payment-connections.destroy');

        // Payment attempts and refunds. `payments/{payment}` is tenant-scoped
        // by the model's global scope, so a foreign id is a 404.
        Route::get('payments', [MerchantPaymentController::class, 'index'])
            ->middleware('permission:payments.view')->name('payments.index');
        Route::get('payments/{payment}', [MerchantPaymentController::class, 'show'])
            ->middleware('permission:payments.view')->name('payments.show');
        Route::post('payments/{payment}/sync', [MerchantPaymentController::class, 'sync'])
            ->middleware('permission:payments.manage')->name('payments.sync');
        Route::post('payments/{payment}/refunds', [MerchantPaymentController::class, 'refund'])
            ->middleware('permission:payments.refund')->name('payments.refunds.store');

        // Store base currency. Reading the catalogue and previewing a change is
        // a view operation; performing the conversion is a financial write.
        Route::get('currencies', [StoreCurrencyController::class, 'options'])->middleware('permission:stores.view')->name('currencies.index');
        Route::get('stores/{store}/currency', [StoreCurrencyController::class, 'show'])->middleware('permission:stores.view')->name('stores.currency.show');
        Route::get('stores/{store}/currency/preview', [StoreCurrencyController::class, 'preview'])->middleware('permission:stores.view')->name('stores.currency.preview');
        Route::post('stores/{store}/currency', [StoreCurrencyController::class, 'convert'])->middleware('permission:stores.manage')->name('stores.currency.convert');

        // Workspace settings: readers may see the tenant, managers may change it.
        Route::get('settings', [TenantSettingsController::class, 'show'])->middleware('permission:settings.view')->name('settings.show');
        Route::patch('settings', [TenantSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');

        /*
         * The workspace's AI settings: which AI features run here, its own
         * consumption, and — only while the platform has enabled BYOK — the
         * merchant's own provider credentials. Strictly the merchant half of
         * the AI surface: no provider administration, no routing, no prompts,
         * and the platform's credentials are never visible from here.
         */
        Route::prefix('ai-settings')->name('ai-settings.')->group(function (): void {
            Route::get('/', [TenantAiSettingsController::class, 'index'])
                ->middleware('permission:ai_settings.view')->name('index');
            Route::patch('/', [TenantAiSettingsController::class, 'update'])
                ->middleware('permission:ai_settings.manage')->name('update');

            Route::get('usage', [TenantAiUsageController::class, 'index'])
                ->middleware('permission:ai_settings.view')->name('usage');

            Route::get('credentials', [TenantAiCredentialController::class, 'index'])
                ->middleware('permission:ai_settings.view')->name('credentials.index');
            Route::post('credentials', [TenantAiCredentialController::class, 'store'])
                ->middleware('permission:ai_settings.manage')->name('credentials.store');
            Route::post('credentials/test', [TenantAiCredentialController::class, 'test'])
                ->middleware('permission:ai_settings.manage')->name('credentials.test');
            Route::delete('credentials/{credential}', [TenantAiCredentialController::class, 'revoke'])
                ->middleware('permission:ai_settings.manage')->name('credentials.revoke');
        });

        // Catalogue
        // Categories are addressed by their internal id: unlike products they
        // have no public URL of their own, so an id is the honest identifier.
        // `slug-availability` precedes `{category}` so it is never parsed as an
        // id.
        Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:categories.view')->name('categories.index');
        Route::get('categories/tree', [CategoryController::class, 'tree'])->middleware('permission:categories.view')->name('categories.tree');
        Route::get('categories/slug-availability', [CategoryController::class, 'slugAvailability'])->middleware('permission:categories.view')->name('categories.slug-availability');
        Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:categories.manage')->name('categories.store');
        Route::get('categories/{category}', [CategoryController::class, 'show'])->middleware('permission:categories.view')->name('categories.show');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.manage')->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories.manage')->name('categories.destroy');

        // Products are addressed by slug: the merchant-facing URL is
        // /products/{slug}, so the API contract resolves the same way. Binding
        // by slug also means a foreign tenant's slug is a plain 404 through the
        // tenant scope, never a leak of another merchant's row.
        Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
        Route::get('products/slug-availability', [ProductController::class, 'slugAvailability'])->middleware('permission:products.view')->name('products.slug-availability');
        Route::get('products/{product:slug}', [ProductController::class, 'show'])->middleware('permission:products.view')->name('products.show');
        Route::patch('products/{product:slug}', [ProductController::class, 'update'])->middleware('permission:products.update')->name('products.update');
        Route::delete('products/{product:slug}', [ProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');

        // Product gallery. Editing a product's images needs products.update, the
        // same permission as editing the product itself — an image is part of
        // that product's record, not a separate resource. `images/reorder`
        // precedes the bound `{image}` routes so "reorder" is never parsed as id.
        Route::post('products/{product:slug}/images/reorder', [ProductImageController::class, 'reorder'])->middleware('permission:products.update')->name('products.images.reorder');
        Route::post('products/{product:slug}/images', [ProductImageController::class, 'store'])->middleware('permission:products.update')->name('products.images.store');
        Route::patch('products/{product:slug}/images/{image}', [ProductImageController::class, 'update'])->middleware('permission:products.update')->name('products.images.update');
        Route::delete('products/{product:slug}/images/{image}', [ProductImageController::class, 'destroy'])->middleware('permission:products.update')->name('products.images.destroy');

        // Smart Bundles. The two recommendation routes are registered before
        // `bundles/{bundle}` so "recommendations" is never parsed as a bundle id.
        // Bundles reuse the catalogue permissions rather than inventing a
        // parallel grant for the same administrative job.
        Route::get('bundles/recommendations', [BundleRecommendationController::class, 'index'])
            ->middleware(['permission:products.view'])->name('bundles.recommendations.index');
        Route::post('bundles/recommendations/explain', [BundleRecommendationController::class, 'explain'])
            ->middleware(['permission:products.view', 'permission:ai.use'])->name('bundles.recommendations.explain');
        Route::get('bundles', [BundleController::class, 'index'])->middleware('permission:products.view')->name('bundles.index');
        Route::post('bundles', [BundleController::class, 'store'])->middleware('permission:products.create')->name('bundles.store');
        Route::get('bundles/{bundle}', [BundleController::class, 'show'])->middleware('permission:products.view')->name('bundles.show');
        Route::get('bundles/{bundle}/preview', [BundleController::class, 'preview'])->middleware('permission:products.view')->name('bundles.preview');
        Route::patch('bundles/{bundle}', [BundleController::class, 'update'])->middleware('permission:products.update')->name('bundles.update');
        Route::delete('bundles/{bundle}', [BundleController::class, 'destroy'])->middleware('permission:products.delete')->name('bundles.destroy');

        // Inventory
        Route::get('inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view')->name('inventory.index');
        Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->middleware('permission:inventory.view')->name('inventory.low-stock');
        Route::get('inventory/intelligence', [InventoryIntelligenceController::class, 'overview'])->middleware('permission:inventory.view')->name('inventory.intelligence');
        Route::get('inventory/intelligence/export', [InventoryIntelligenceController::class, 'export'])->middleware('permission:inventory.view')->name('inventory.intelligence.export');
        // The two action routes precede {inventory} so "low-stock", "intelligence"
        // and "adjust" are never parsed as an inventory id.
        Route::get('inventory/{inventory}/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view')->name('inventory.movements');
        Route::get('inventory/{inventory}/intelligence', [InventoryIntelligenceController::class, 'show'])->middleware('permission:inventory.view')->name('inventory.intelligence.show');
        Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->middleware('permission:inventory.manage')->name('inventory.adjust');
        Route::post('inventory/receive', [InventoryController::class, 'receive'])->middleware('permission:inventory.manage')->name('inventory.receive');
        Route::post('inventory/transfers', [InventoryController::class, 'transfer'])->middleware('permission:inventory.manage')->name('inventory.transfers.store');

        // Customers
        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create')->name('customers.store');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view')->name('customers.show');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update')->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete')->name('customers.destroy');

        /*
         * Provider identities. Reading is part of reading the customer;
         * linking and unlinking are explicit edits. The resolver never merges
         * on a name or username, so this is the only way two channels become
         * one customer.
         */
        Route::get('customers/{customer}/identities', [CustomerIdentityController::class, 'index'])
            ->middleware('permission:customers.view')->name('customers.identities.index');
        Route::post('customers/{customer}/identities', [CustomerIdentityController::class, 'store'])
            ->middleware('permission:customers.update')->name('customers.identities.store');
        Route::delete('customers/{customer}/identities/{identity}', [CustomerIdentityController::class, 'destroy'])
            ->middleware('permission:customers.update')->name('customers.identities.destroy');

        // Customer CRM / Memory
        Route::get('customers/{customer}/crm/overview', [CustomerCrmController::class, 'overview'])->middleware('permission:customers.view')->name('customers.crm.overview');
        Route::get('customers/{customer}/crm/timeline', [CustomerCrmController::class, 'timeline'])->middleware('permission:customers.view')->name('customers.crm.timeline');
        Route::get('customers/{customer}/crm/summary', [CustomerCrmController::class, 'summary'])->middleware('permission:customers.view')->name('customers.crm.summary');
        Route::post('customers/{customer}/crm/summary/refresh', [CustomerCrmController::class, 'refreshSummary'])->middleware(['permission:customers.view', 'permission:ai.use'])->name('customers.crm.summary.refresh');
        Route::patch('customers/{customer}/crm/summary', [CustomerCrmController::class, 'updateSummary'])->middleware('permission:customers.update')->name('customers.crm.summary.update');

        Route::get('customers/{customer}/memories', [CustomerMemoryController::class, 'index'])->middleware('permission:customers.view')->name('customers.memories.index');
        Route::post('customers/{customer}/memories', [CustomerMemoryController::class, 'store'])->middleware('permission:customers.update')->name('customers.memories.store');
        Route::patch('customers/{customer}/memories/{memory}', [CustomerMemoryController::class, 'update'])->middleware('permission:customers.update')->name('customers.memories.update');
        Route::delete('customers/{customer}/memories/{memory}', [CustomerMemoryController::class, 'destroy'])->middleware('permission:customers.delete')->name('customers.memories.destroy');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
        Route::post('orders', [OrderController::class, 'store'])->middleware('permission:orders.create')->name('orders.store');
        // The merchant-facing identifier is the human order number
        // (ORD-000123). Reads resolve against it; every internal mutation
        // (update, transition, delete) keeps using the primary key so history
        // and stock side effects are never keyed on a value a client can guess.
        Route::get('orders/{order}/transitions', [OrderController::class, 'transitions'])->middleware('permission:orders.view')->name('orders.transitions');
        Route::get('orders/{order:order_number}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');
        Route::patch('orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update')->name('orders.update');
        Route::post('orders/{order}/transition', [OrderController::class, 'transition'])->middleware('permission:orders.update')->name('orders.transition');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete')->name('orders.destroy');

        // Inbox. Static segments precede {conversation} so "assignees" and
        // "summary" are never resolved as a conversation primary key.
        Route::get('conversations/summary', [ConversationController::class, 'summary'])->middleware('permission:conversations.view')->name('conversations.summary');
        Route::get('conversations/assignees', [ConversationController::class, 'assignees'])->middleware('permission:conversations.view')->name('conversations.assignees');
        Route::get('conversations', [ConversationController::class, 'index'])->middleware('permission:conversations.view')->name('conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])->middleware('permission:conversations.reply')->name('conversations.store');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->middleware('permission:conversations.view')->name('conversations.show');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->middleware('permission:conversations.assign')->name('conversations.update');
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->middleware('permission:conversations.assign')->name('conversations.destroy');
        Route::get('conversations/{conversation}/events', [ConversationEventController::class, 'index'])->middleware('permission:conversations.view')->name('conversations.events');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->middleware('permission:conversations.view')->name('conversations.messages');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware('permission:conversations.reply')->name('conversations.reply');
        Route::get('conversations/{conversation}/voice-reply', [ConversationController::class, 'voiceReplyAvailability'])->middleware('permission:conversations.view')->name('conversations.voice-reply.availability');
        Route::post('conversations/{conversation}/voice-reply', [ConversationController::class, 'voiceReply'])->middleware('permission:conversations.reply')->name('conversations.voice-reply');
        Route::post('conversations/{conversation}/notes', [ConversationController::class, 'note'])->middleware('permission:conversations.reply')->name('conversations.notes');
        Route::post('conversations/{conversation}/templates', [ConversationController::class, 'template'])->middleware('permission:conversations.reply')->name('conversations.templates');
        Route::post('conversations/{conversation}/messages/{message}/retry', [ConversationController::class, 'retry'])->middleware('permission:conversations.reply')->name('conversations.retry');
        Route::get('conversations/{conversation}/messages/{message}/media', [ConversationController::class, 'media'])->middleware('permission:conversations.view')->name('conversations.media');
        Route::get('conversations/{conversation}/voice-messages/{voiceMessage}', [VoiceMessageController::class, 'show'])
            ->middleware('permission:conversations.view')->name('conversations.voice.show');
        Route::post('conversations/{conversation}/voice-messages/{voiceMessage}/retry', [VoiceMessageController::class, 'retry'])
            ->middleware(['permission:ai.use', 'permission:conversations.view'])->name('conversations.voice.retry');
        Route::post('conversations/{conversation}/voice-messages/{voiceMessage}/apply', [VoiceMessageController::class, 'apply'])
            ->middleware(['permission:ai.use', 'permission:orders.create', 'permission:conversations.view'])->name('conversations.voice.apply');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead'])->middleware('permission:conversations.view')->name('conversations.read');
        Route::post('conversations/{conversation}/assign', [ConversationController::class, 'assign'])->middleware('permission:conversations.assign')->name('conversations.assign');

        // Conversation order builder. A cart belongs to a thread, so every
        // route also requires the conversation it reads to be visible. No stock
        // moves until confirmation converts the proposal into a pending order.
        Route::get('conversations/{conversation}/cart', [CartController::class, 'show'])
            ->middleware(['permission:orders.view', 'permission:conversations.view'])->name('conversations.cart.show');
        Route::post('conversations/{conversation}/cart', [CartController::class, 'store'])
            ->middleware(['permission:orders.create', 'permission:conversations.view'])->name('conversations.cart.store');
        Route::patch('conversations/{conversation}/cart', [CartController::class, 'update'])
            ->middleware(['permission:orders.update', 'permission:conversations.view'])->name('conversations.cart.update');
        Route::delete('conversations/{conversation}/cart', [CartController::class, 'cancel'])
            ->middleware(['permission:orders.update', 'permission:conversations.view'])->name('conversations.cart.cancel');
        Route::post('conversations/{conversation}/cart/items', [CartController::class, 'addItem'])
            ->middleware(['permission:orders.update', 'permission:conversations.view'])->name('conversations.cart.items.store');
        Route::patch('conversations/{conversation}/cart/items/{item}', [CartController::class, 'updateItem'])
            ->middleware(['permission:orders.update', 'permission:conversations.view'])->name('conversations.cart.items.update');
        Route::delete('conversations/{conversation}/cart/items/{item}', [CartController::class, 'removeItem'])
            ->middleware(['permission:orders.update', 'permission:conversations.view'])->name('conversations.cart.items.destroy');
        Route::post('conversations/{conversation}/cart/request-confirmation', [CartController::class, 'requestConfirmation'])
            ->middleware(['permission:orders.update', 'permission:conversations.reply'])->name('conversations.cart.request-confirmation');
        Route::post('conversations/{conversation}/cart/reopen', [CartController::class, 'reopen'])
            ->middleware(['permission:orders.update', 'permission:conversations.view'])->name('conversations.cart.reopen');
        Route::post('conversations/{conversation}/cart/confirm', [CartController::class, 'confirm'])
            ->middleware(['permission:orders.create', 'permission:conversations.view'])->name('conversations.cart.confirm');

        // Lost-sale recovery. Detection is a scheduled job; this surface only
        // reads the resulting records and records explicit human decisions.
        Route::get('recovery-opportunities/summary', [RecoveryOpportunityController::class, 'summary'])
            ->middleware('permission:recovery.view')->name('recovery.summary');
        Route::get('recovery-opportunities', [RecoveryOpportunityController::class, 'index'])
            ->middleware('permission:recovery.view')->name('recovery.index');
        Route::get('recovery-opportunities/{recoveryOpportunity}', [RecoveryOpportunityController::class, 'show'])
            ->middleware('permission:recovery.view')->name('recovery.show');
        Route::post('recovery-opportunities/{recoveryOpportunity}/ai-draft', [RecoveryOpportunityController::class, 'draft'])
            ->middleware(['permission:ai.use', 'permission:recovery.view'])->name('recovery.ai-draft');
        Route::post('recovery-opportunities/{recoveryOpportunity}/contact', [RecoveryOpportunityController::class, 'contact'])
            ->middleware(['permission:recovery.manage', 'permission:conversations.reply'])->name('recovery.contact');
        Route::post('recovery-opportunities/{recoveryOpportunity}/assign', [RecoveryOpportunityController::class, 'assign'])
            ->middleware('permission:recovery.manage')->name('recovery.assign');
        Route::post('recovery-opportunities/{recoveryOpportunity}/snooze', [RecoveryOpportunityController::class, 'snooze'])
            ->middleware('permission:recovery.manage')->name('recovery.snooze');
        Route::post('recovery-opportunities/{recoveryOpportunity}/dismiss', [RecoveryOpportunityController::class, 'dismiss'])
            ->middleware('permission:recovery.manage')->name('recovery.dismiss');
        Route::post('recovery-opportunities/{recoveryOpportunity}/do-not-contact', [RecoveryOpportunityController::class, 'doNotContact'])
            ->middleware('permission:recovery.manage')->name('recovery.do-not-contact');

        // Channel settings. The static `configuration` read is registered before
        // `{whatsappAccount}` for the same reason `conversations/summary` is: a
        // parameterised segment would otherwise swallow it and answer a 404.
        Route::get('whatsapp-accounts', [WhatsappAccountController::class, 'index'])->middleware('permission:whatsapp.view')->name('whatsapp.index');
        Route::get('whatsapp-accounts/configuration', [WhatsappAccountController::class, 'configuration'])->middleware('permission:whatsapp.view')->name('whatsapp.configuration');
        Route::post('whatsapp-accounts', [WhatsappAccountController::class, 'store'])->middleware('permission:whatsapp.manage')->name('whatsapp.store');
        Route::get('whatsapp-accounts/{whatsappAccount}', [WhatsappAccountController::class, 'show'])->middleware('permission:whatsapp.view')->name('whatsapp.show');
        Route::patch('whatsapp-accounts/{whatsappAccount}', [WhatsappAccountController::class, 'update'])->middleware('permission:whatsapp.manage')->name('whatsapp.update');
        Route::delete('whatsapp-accounts/{whatsappAccount}', [WhatsappAccountController::class, 'destroy'])->middleware('permission:whatsapp.manage')->name('whatsapp.destroy');
        Route::post('whatsapp-accounts/{whatsappAccount}/verify', [WhatsappAccountController::class, 'verify'])->middleware('permission:whatsapp.manage')->name('whatsapp.verify');
        Route::post('whatsapp-accounts/{whatsappAccount}/disconnect', [WhatsappAccountController::class, 'disconnect'])->middleware('permission:whatsapp.manage')->name('whatsapp.disconnect');

        /*
         * The merchant integration centre. WhatsApp remains manageable through
         * its own settings above; the generic connections here add the other
         * channels and expose one consistent health surface. Credentials are
         * write-only and encrypted at rest.
         */
        Route::prefix('integrations')->name('integrations.')->group(function (): void {
            Route::get('/', [IntegrationController::class, 'index'])
                ->middleware('permission:integrations.view')->name('index');
            Route::post('/', [IntegrationController::class, 'store'])
                ->middleware('permission:integrations.manage')->name('store');
            Route::get('{connection}', [IntegrationController::class, 'show'])
                ->middleware('permission:integrations.view')->name('show');
            Route::patch('{connection}', [IntegrationController::class, 'update'])
                ->middleware('permission:integrations.manage')->name('update');
            Route::delete('{connection}', [IntegrationController::class, 'destroy'])
                ->middleware('permission:integrations.manage')->name('destroy');
            Route::post('{connection}/test', [IntegrationController::class, 'test'])
                ->middleware('permission:integrations.manage')->name('test');
            Route::post('{connection}/reconnect', [IntegrationController::class, 'reconnect'])
                ->middleware('permission:integrations.manage')->name('reconnect');
        });

        // Reporting
        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard.index');
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->middleware('permission:dashboard.view')->name('dashboard.summary');
        Route::get('dashboard/revenue', [DashboardController::class, 'revenue'])->middleware('permission:dashboard.view')->name('dashboard.revenue');
        Route::get('dashboard/top-products', [DashboardController::class, 'topProducts'])->middleware('permission:dashboard.view')->name('dashboard.top-products');
        Route::get('dashboard/dead-stock', [DashboardController::class, 'deadStock'])->middleware('permission:dashboard.view')->name('dashboard.dead-stock');
        Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('permission:dashboard.view')->name('analytics.index');
        Route::get('analytics/metrics', [AnalyticsController::class, 'metrics'])->middleware('permission:dashboard.view')->name('analytics.metrics');

        /*
         * Profit intelligence is financial data, so it has its own permission
         * rather than riding on the operational dashboard. The export has a
         * separate grant: reading a report and removing it from the workspace
         * are different decisions.
         */
        Route::get('profit', [ProfitIntelligenceController::class, 'overview'])->middleware('permission:profit.view')->name('profit.index');
        Route::get('profit/export', [ProfitIntelligenceController::class, 'export'])->middleware('permission:profit.export')->name('profit.export');

        /*
         * AI assistance. Drafting a reply reads a conversation, so the route
         * needs both the feature permission and conversation read access; the
         * tenant briefing sits on top of the dashboard permission because it
         * summarises exactly those aggregates. Both only ever return suggestions.
         */
        Route::post('conversations/{conversation}/ai-reply-draft', [ConversationAssistantController::class, 'draftReply'])
            ->middleware(['permission:ai.use', 'permission:conversations.view'])->name('conversations.reply-draft');
        Route::post('conversations/{conversation}/ai-sales-copilot', [SalesCopilotController::class, 'suggest'])
            ->middleware(['permission:ai.use', 'permission:conversations.view'])->name('conversations.ai-sales-copilot');
        Route::post('ai/merchant-copilot', [MerchantCopilotController::class, 'ask'])
            ->middleware(['permission:ai.use', 'permission:dashboard.view'])->name('ai.merchant-copilot');
        Route::get('ai/insights', [InsightsController::class, 'tenant'])
            ->middleware(['permission:ai.use', 'permission:dashboard.view'])->name('ai.insights');
        Route::get('ai/availability', [AiAvailabilityController::class, 'index'])
            ->middleware(['permission:ai.use'])->name('ai.availability');
        Route::post('conversations/{conversation}/ai-summary', [AiSummaryController::class, 'dispatch'])
            ->middleware(['permission:ai.use', 'permission:conversations.view'])->name('conversations.ai-summary');
        Route::get('ai/action-types', [AiActionController::class, 'types'])
            ->middleware(['permission:ai.use'])->name('ai.actions.types');
        Route::get('ai/actions', [AiActionController::class, 'index'])
            ->middleware(['permission:ai.use'])->name('ai.actions.index');
        Route::post('ai/actions', [AiActionController::class, 'store'])
            ->middleware(['permission:ai.use'])->name('ai.actions.store');
        Route::get('ai/actions/{action}', [AiActionController::class, 'show'])
            ->middleware(['permission:ai.use'])->name('ai.actions.show');
        Route::post('ai/actions/{action}/submit', [AiActionController::class, 'submit'])
            ->middleware(['permission:ai.use'])->name('ai.actions.submit');
        Route::post('ai/actions/{action}/approve', [AiActionController::class, 'approve'])
            ->middleware(['permission:ai.approve'])->name('ai.actions.approve');
        Route::post('ai/actions/{action}/reject', [AiActionController::class, 'reject'])
            ->middleware(['permission:ai.approve'])->name('ai.actions.reject');
        Route::post('ai/actions/{action}/execute', [AiActionController::class, 'execute'])
            ->middleware(['permission:ai.approve'])->name('ai.actions.execute');

        /*
         * AI translation review. Every translatable entity (products,
         * categories, shipping zones, store copy, automations) uses the same
         * generic surface: the registry decides the fields, the service decides
         * the workflow and the workspace scope is resolved before any id is
         * accepted. Generation is only a proposal; approval is separate.
         */
        Route::prefix('translations')->name('translations.')->group(function (): void {
            Route::get('entities', [TranslationController::class, 'entities'])
                ->middleware('permission:translations.view')->name('entities');
            Route::get('usage', [TranslationController::class, 'usage'])
                ->middleware('permission:translations.view')->name('usage');
            Route::get('{entity}/{id}', [TranslationController::class, 'show'])
                ->middleware('permission:translations.view')->name('show');
            Route::post('{entity}/{id}/generate', [TranslationController::class, 'generate'])
                ->middleware('permission:translations.generate')->name('generate');
            Route::put('{entity}/{id}', [TranslationController::class, 'save'])
                ->middleware('permission:translations.review')->name('save');
            Route::post('{entity}/{id}/approve-all', [TranslationController::class, 'approveAll'])
                ->middleware('permission:translations.review')->name('approve-all');
            Route::patch('{translation}', [TranslationController::class, 'update'])
                ->middleware('permission:translations.review')->name('update');
            Route::post('{translation}/approve', [TranslationController::class, 'approve'])
                ->middleware('permission:translations.review')->name('approve');
            Route::post('{translation}/reject', [TranslationController::class, 'reject'])
                ->middleware('permission:translations.review')->name('reject');
            Route::post('{translation}/regenerate', [TranslationController::class, 'regenerate'])
                ->middleware('permission:translations.review')->name('regenerate');
        });

        /*
         * Automations. Configuration is a manager decision, so rules require
         * `automation.manage`; run history and the internal task queue are
         * readable by anyone who may see automations. Completing a task is a
         * work action on a human queue, not a rule change, so it uses `view`.
         */
        Route::prefix('automation')->name('automation.')->group(function (): void {
            Route::get('meta', [AutomationMetaController::class, 'show'])
                ->middleware('permission:automation.view')->name('meta');
            Route::get('rules', [AutomationRuleController::class, 'index'])
                ->middleware('permission:automation.view')->name('rules.index');
            Route::post('rules', [AutomationRuleController::class, 'store'])
                ->middleware('permission:automation.manage')->name('rules.store');
            Route::get('rules/{automationRule}', [AutomationRuleController::class, 'show'])
                ->middleware('permission:automation.view')->name('rules.show');
            Route::patch('rules/{automationRule}', [AutomationRuleController::class, 'update'])
                ->middleware('permission:automation.manage')->name('rules.update');
            Route::delete('rules/{automationRule}', [AutomationRuleController::class, 'destroy'])
                ->middleware('permission:automation.manage')->name('rules.destroy');
            Route::get('runs', [AutomationRunController::class, 'index'])
                ->middleware('permission:automation.view')->name('runs.index');
            Route::get('runs/{automationRun}', [AutomationRunController::class, 'show'])
                ->middleware('permission:automation.view')->name('runs.show');
            Route::post('runs/{automationRun}/retry', [AutomationRunController::class, 'retry'])
                ->middleware('permission:automation.manage')->name('runs.retry');

            /*
             * The template library. Templates themselves are platform-owned and
             * global; installing one only creates a tenant-owned rule. Installed
             * rules start paused, so every write here needs `automation.manage`.
             */
            Route::get('templates', [AutomationTemplateController::class, 'index'])
                ->middleware('permission:automation.view')->name('templates.index');
            Route::get('templates/{automationTemplate}', [AutomationTemplateController::class, 'show'])
                ->middleware('permission:automation.view')->name('templates.show');
            Route::post('templates/{automationTemplate}/install', [AutomationTemplateController::class, 'install'])
                ->middleware('permission:automation.manage')->name('templates.install');

            Route::get('installations', [AutomationInstallationController::class, 'index'])
                ->middleware('permission:automation.view')->name('installations.index');
            Route::get('installations/{installation}', [AutomationInstallationController::class, 'show'])
                ->middleware('permission:automation.view')->name('installations.show');
            Route::patch('installations/{installation}', [AutomationInstallationController::class, 'update'])
                ->middleware('permission:automation.manage')->name('installations.update');
            Route::get('installations/{installation}/update-preview', [AutomationInstallationController::class, 'preview'])
                ->middleware('permission:automation.view')->name('installations.preview');
            Route::post('installations/{installation}/apply-update', [AutomationInstallationController::class, 'applyUpdate'])
                ->middleware('permission:automation.manage')->name('installations.apply-update');
            Route::post('installations/{installation}/enable', [AutomationInstallationController::class, 'enable'])
                ->middleware('permission:automation.manage')->name('installations.enable');
            Route::post('installations/{installation}/disable', [AutomationInstallationController::class, 'disable'])
                ->middleware('permission:automation.manage')->name('installations.disable');
            Route::delete('installations/{installation}', [AutomationInstallationController::class, 'destroy'])
                ->middleware('permission:automation.manage')->name('installations.destroy');

            Route::get('tasks', [AutomationTaskController::class, 'index'])
                ->middleware('permission:automation.view')->name('tasks.index');
            Route::get('tasks/{automationTask}', [AutomationTaskController::class, 'show'])
                ->middleware('permission:automation.view')->name('tasks.show');
            Route::patch('tasks/{automationTask}', [AutomationTaskController::class, 'update'])
                ->middleware('permission:automation.view')->name('tasks.update');
        });

        // Audit
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
        Route::get('audit-logs/actions', [AuditLogController::class, 'actions'])->middleware('permission:audit.view')->name('audit.actions');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit.view')->name('audit.show');
    });
});
