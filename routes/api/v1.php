<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Ai\ContentAssistantController;
use App\Http\Controllers\Api\V1\Ai\ConversationAssistantController;
use App\Http\Controllers\Api\V1\Ai\InsightsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\Marketing\ContactController;
use App\Http\Controllers\Api\V1\Marketing\ContentPageController as MarketingContentPageController;
use App\Http\Controllers\Api\V1\Marketing\FaqController;
use App\Http\Controllers\Api\V1\Marketing\FeatureController;
use App\Http\Controllers\Api\V1\Marketing\NewsletterArticleController as MarketingNewsletterArticleController;
use App\Http\Controllers\Api\V1\Marketing\NewsletterController;
use App\Http\Controllers\Api\V1\Marketing\PricingController;
use App\Http\Controllers\Api\V1\Marketing\WaitlistController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform\ContactMessageController;
use App\Http\Controllers\Api\V1\Platform\ContentPageController as PlatformContentPageController;
use App\Http\Controllers\Api\V1\Platform\FaqEntryController;
use App\Http\Controllers\Api\V1\Platform\NewsletterController as PlatformNewsletterController;
use App\Http\Controllers\Api\V1\Platform\NewsletterSubscriberController;
use App\Http\Controllers\Api\V1\Platform\PlatformDashboardController;
use App\Http\Controllers\Api\V1\Platform\PlatformInsightsController;
use App\Http\Controllers\Api\V1\Platform\PricingPlanController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\Platform\UserController as PlatformUserController;
use App\Http\Controllers\Api\V1\Platform\WaitlistEntryController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProfileSessionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WhatsappAccountController;
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

// Provider callbacks. Meta cannot present a Sanctum token, so these routes stay
// outside the authenticated group. The handler resolves the tenant from the
// connected account rather than trusting the payload.
Route::prefix('webhooks')->group(function (): void {
    Route::get('whatsapp', [WhatsappWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');
    Route::post('whatsapp', [WhatsappWebhookController::class, 'events'])
        ->name('webhooks.whatsapp.events');
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
});

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->middleware('tenant.optional')->name('api.me');
    Route::get('permissions', [PermissionController::class, 'index'])->name('api.permissions.index');

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
        Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
        Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
        Route::put('password', [ProfileController::class, 'updatePassword'])->name('password.update');

        /*
         * Signed-in devices. `sessions/{token}` must stay below the bare
         * `sessions` route so `DELETE /profile/sessions` matches the "sign out
         * every other device" action and never parses as an id.
         */
        Route::get('sessions', [ProfileSessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions', [ProfileSessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('sessions/{token}', [ProfileSessionController::class, 'destroy'])->name('sessions.destroy');

        Route::get('notifications/channels', [NotificationPreferenceController::class, 'channels'])->name('notifications.channels');
        Route::get('notifications', [NotificationPreferenceController::class, 'show'])->name('notifications.show');
        Route::put('notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');
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
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
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
         * AI assistance for Dukavo staff. Platform-level because both endpoints
         * work on Dukavo's own data (the cross-tenant overview and the marketing
         * newsletters), never a merchant workspace. `permission:ai.use` is held by
         * Super Admin and Dukavo Admin, and can be revoked per role.
         */
        Route::get('ai/insights', [PlatformInsightsController::class, 'index'])
            ->middleware('permission:ai.use')->name('ai.insights');
        Route::post('ai/newsletter-content', [ContentAssistantController::class, 'generate'])
            ->middleware('permission:ai.use')->name('ai.newsletter-content');

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
        Route::get('newsletters/slug-availability', [PlatformNewsletterController::class, 'slugAvailability'])->name('newsletters.slug-availability');
        Route::get('newsletters/{newsletter}', [PlatformNewsletterController::class, 'show'])->name('newsletters.show');
        Route::patch('newsletters/{newsletter}', [PlatformNewsletterController::class, 'update'])->name('newsletters.update');
        Route::delete('newsletters/{newsletter}', [PlatformNewsletterController::class, 'destroy'])->name('newsletters.destroy');
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

        // Workspace settings: readers may see the tenant, managers may change it.
        Route::get('settings', [TenantSettingsController::class, 'show'])->middleware('permission:settings.view')->name('settings.show');
        Route::patch('settings', [TenantSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');

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

        // Inventory
        Route::get('inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view')->name('inventory.index');
        Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->middleware('permission:inventory.view')->name('inventory.low-stock');
        // The two action routes precede {inventory} so "low-stock" and "adjust"
        // are never parsed as an inventory id.
        Route::get('inventory/{inventory}/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view')->name('inventory.movements');
        Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->middleware('permission:inventory.manage')->name('inventory.adjust');
        Route::post('inventory/receive', [InventoryController::class, 'receive'])->middleware('permission:inventory.manage')->name('inventory.receive');

        // Customers
        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create')->name('customers.store');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view')->name('customers.show');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update')->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete')->name('customers.destroy');

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

        // Inbox
        Route::get('conversations/summary', [ConversationController::class, 'summary'])->middleware('permission:conversations.view')->name('conversations.summary');
        Route::get('conversations', [ConversationController::class, 'index'])->middleware('permission:conversations.view')->name('conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])->middleware('permission:conversations.reply')->name('conversations.store');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->middleware('permission:conversations.view')->name('conversations.show');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->middleware('permission:conversations.assign')->name('conversations.update');
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->middleware('permission:conversations.assign')->name('conversations.destroy');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->middleware('permission:conversations.view')->name('conversations.messages');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware('permission:conversations.reply')->name('conversations.reply');
        Route::post('conversations/{conversation}/assign', [ConversationController::class, 'assign'])->middleware('permission:conversations.assign')->name('conversations.assign');

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

        // Reporting
        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard.index');
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->middleware('permission:dashboard.view')->name('dashboard.summary');
        Route::get('dashboard/revenue', [DashboardController::class, 'revenue'])->middleware('permission:dashboard.view')->name('dashboard.revenue');
        Route::get('dashboard/top-products', [DashboardController::class, 'topProducts'])->middleware('permission:dashboard.view')->name('dashboard.top-products');
        Route::get('dashboard/dead-stock', [DashboardController::class, 'deadStock'])->middleware('permission:dashboard.view')->name('dashboard.dead-stock');

        /*
         * AI assistance. Drafting a reply reads a conversation, so the route
         * needs both the feature permission and conversation read access; the
         * tenant briefing sits on top of the dashboard permission because it
         * summarises exactly those aggregates. Both only ever return suggestions.
         */
        Route::post('conversations/{conversation}/ai-reply-draft', [ConversationAssistantController::class, 'draftReply'])
            ->middleware(['permission:ai.use', 'permission:conversations.view'])->name('conversations.reply-draft');
        Route::get('ai/insights', [InsightsController::class, 'tenant'])
            ->middleware(['permission:ai.use', 'permission:dashboard.view'])->name('ai.insights');

        // Audit
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
        Route::get('audit-logs/actions', [AuditLogController::class, 'actions'])->middleware('permission:audit.view')->name('audit.actions');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit.view')->name('audit.show');
    });
});
