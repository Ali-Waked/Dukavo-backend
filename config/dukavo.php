<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform
    |--------------------------------------------------------------------------
    |
    | Identity of the Dukavo platform itself. `name` is what the SPA shows in
    | the sidebar; nothing here affects tenant data.
    |
    */

    'name' => env('DUKAVO_NAME', 'Dukavo'),

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Languages the platform serves. `default` is the fallback every public
    | surface uses when a request does not ask for a supported language, and it
    | is the only language guaranteed to have localized content: a missing
    | translation falls back to it rather than showing an empty page.
    |
    */

    'locales' => [
        'supported' => ['en', 'ar'],
        'default' => 'en',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute for authenticated API traffic and for the tighter
    | write endpoints that create data (orders, products). Rate limiting is a
    | security control, not a performance knob, so the values live here.
    |
    */

    'rate_limits' => [
        'api' => (int) env('DUKAVO_API_RATE_LIMIT', 120),
        'write' => (int) env('DUKAVO_WRITE_RATE_LIMIT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Context Header
    |--------------------------------------------------------------------------
    |
    | Request header a multi-tenant user may use to *select* which of their
    | tenants to act in. It is only ever a hint: the middleware verifies the
    | user actually holds a role in the requested tenant before accepting it.
    |
    */

    'tenant_header' => env('DUKAVO_TENANT_HEADER', 'X-Tenant'),

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | Decimal precision used for every monetary column. Changing this after
    | launch would require migrating existing data.
    |
    */

    'money' => [
        'total' => 12,
        'places' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | The supported ISO 4217 currencies and everything the UI needs to present
    | them. A store owns exactly one base currency; products and inventory are
    | priced in it. `decimals` is the minor-unit precision used for storage and
    | formatting (JPY has none, the Gulf dinars have three).
    |
    | The catalogue itself lives in `config/currencies.php`, generated from
    | official ISO 4217 minor units and ICU/CLDR names and symbols. Adding a
    | currency there is the only way to make it selectable: validation and the
    | currency API both read this list, so an unknown code cannot be persisted.
    |
    */

    'currencies' => [
        'default' => env('DUKAVO_DEFAULT_CURRENCY', 'USD'),

        'supported' => require __DIR__.'/currencies.php',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange Rates
    |--------------------------------------------------------------------------
    |
    | Server-side only. The SPA never calls a rate provider; it asks Dukavo to
    | preview or perform a conversion. `ttl` is how long a fetched rate is
    | reused, and `stale_after` is the age beyond which a cached rate is refused
    | outright. When the provider is unreachable the conversion fails and no
    | monetary data is touched — a fabricated rate is never a fallback.
    |
    */

    'exchange_rates' => [
        'provider' => env('DUKAVO_EXCHANGE_RATE_PROVIDER', 'open.er-api.com'),
        'base_url' => env('DUKAVO_EXCHANGE_RATE_BASE_URL', 'https://open.er-api.com/v6/latest'),
        'ttl_minutes' => (int) env('DUKAVO_EXCHANGE_RATE_TTL', 360),
        'stale_after_minutes' => (int) env('DUKAVO_EXCHANGE_RATE_STALE_AFTER', 1440),
        'timeout_seconds' => (int) env('DUKAVO_EXCHANGE_RATE_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Paid subscriptions are not implemented: no payment provider has been
    | selected. `provider` is the machine name of the gateway once one exists,
    | and `checkout_enabled` must only be turned on once a real, verified
    | checkout path is wired up. Until both are set, the public pricing surface
    | reports paid plans as *not purchasable* (see PricingPlanResource) instead
    | of implying a checkout that does not exist. The Free plan (price 0) needs
    | no provider and is always purchasable.
    |
    */

    'billing' => [
        'provider' => env('DUKAVO_BILLING_PROVIDER'),
        'checkout_enabled' => (bool) env('DUKAVO_BILLING_CHECKOUT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Numbers
    |--------------------------------------------------------------------------
    |
    | Sequential order numbers are prefixed per tenant, e.g. ACME-000123.
    |
    */

    'order_number' => [
        'prefix' => env('DUKAVO_ORDER_PREFIX', 'ORD'),
        'padding' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Number of days the dashboard revenue/activity windows cover.
    |
    */

    'dashboard' => [
        'range_days' => 30,
        'recent_activity_limit' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Which outbound channels a notification may actually leave on. The in-app
    | copy is always written and is not governed here.
    |
    | `mail.enabled` is on by default because the deployment ships a mail
    | transport; `whatsapp.enabled` is deliberately off until an approved
    | message template exists. Business-initiated WhatsApp requires a template
    | the provider has approved, and sending without one is a delivery that can
    | never succeed — so the channel is recorded as skipped rather than faked.
    |
    | `require_verified_email` stops a transactional email going to an address
    | the account holder has not proven they control.
    |
    */

    'notifications' => [
        'channels' => [
            'mail' => [
                'enabled' => (bool) env('DUKAVO_NOTIFICATION_MAIL', true),
            ],
            'whatsapp' => [
                'enabled' => (bool) env('DUKAVO_NOTIFICATION_WHATSAPP', false),
                'template' => env('DUKAVO_NOTIFICATION_WHATSAPP_TEMPLATE'),
                'language' => env('DUKAVO_NOTIFICATION_WHATSAPP_LANGUAGE', 'en'),
            ],
        ],

        'require_verified_email' => (bool) env('DUKAVO_NOTIFICATION_REQUIRE_VERIFIED_EMAIL', true),

        'retry' => [
            'times' => (int) env('DUKAVO_NOTIFICATION_RETRY_TIMES', 3),
            'backoff' => [60, 300],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email presentation
    |--------------------------------------------------------------------------
    |
    | Brand assets and public links used by the branded email templates. The
    | logo must be an absolute, publicly reachable URL: mail clients fetch it
    | from their own network and cannot resolve a local filesystem path. In
    | production `APP_URL` is HTTPS, so the default below is a public URL.
    |
    | `frontend_url` is where a recipient lands after clicking an email call to
    | action. It is deliberately separate from the API URL: the API is not a
    | human-facing surface.
    |
    */

    'email' => [
        'logo_url' => env(
            'DUKAVO_EMAIL_LOGO_URL',
            rtrim((string) env('APP_URL', 'http://localhost'), '/').'/images/email/dukavo-logo.png',
        ),
        'logo_width' => (int) env('DUKAVO_EMAIL_LOGO_WIDTH', 168),
        'webhook_secret' => env('DUKAVO_EMAIL_WEBHOOK_SECRET'),
        'support_email' => env('DUKAVO_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    ],

    'urls' => [
        'frontend' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketing
    |--------------------------------------------------------------------------
    |
    | Public marketing surface configuration. `features` lists only capability
    | the product actually implements, so `GET /api/v1/public/features` can
    | never advertise something Dukavo cannot do; `status` lets a future entry
    | ship as `coming_soon` before the feature exists.
    |
    | `contact_recipient` receives public contact form submissions. When it is
    | empty the platform falls back to the outgoing mail sender address, so a
    | fresh installation has a working destination without extra setup.
    |
    */

    'marketing' => [
        'contact_recipient' => env('DUKAVO_CONTACT_EMAIL'),

        'features' => [
            [
                'slug' => 'products',
                'name' => 'Products',
                'description' => 'Product catalogue with variants, images, categories and SKU level pricing.',
                'status' => 'available',
            ],
            [
                'slug' => 'inventory',
                'name' => 'Inventory',
                'description' => 'Per store stock levels, reservations, low stock alerts and a full movement ledger.',
                'status' => 'available',
            ],
            [
                'slug' => 'orders',
                'name' => 'Orders',
                'description' => 'Order lifecycle from pending to delivered, with payment states and status history.',
                'status' => 'available',
            ],
            [
                'slug' => 'customers',
                'name' => 'Customers',
                'description' => 'Customer records with addresses, order history and conversation context.',
                'status' => 'available',
            ],
            [
                'slug' => 'conversations',
                'name' => 'Conversations',
                'description' => 'A shared inbox that ties every customer message to the order it is about.',
                'status' => 'available',
            ],
            [
                'slug' => 'team',
                'name' => 'Team & Permissions',
                'description' => 'Merchant and staff roles with per module permissions, scoped to each business.',
                'status' => 'available',
            ],
            [
                'slug' => 'whatsapp',
                'name' => 'WhatsApp integration',
                'description' => 'Connect a WhatsApp Business account and reply to customers from the same inbox.',
                'status' => 'available',
            ],
            [
                'slug' => 'bundles',
                'name' => 'Bundles',
                'description' => 'Sell product bundles at their own price with accurate stock deduction for every component.',
                'status' => 'available',
            ],
            [
                'slug' => 'recovery',
                'name' => 'Lost-sales recovery',
                'description' => 'Surface abandoned opportunities and follow up with an AI-assisted draft the team approves.',
                'status' => 'available',
            ],
            [
                'slug' => 'analytics',
                'name' => 'Analytics',
                'description' => 'Revenue, order and stock dashboards over the last 30 days, with the trends that need attention.',
                'status' => 'available',
            ],
            [
                'slug' => 'automation',
                'name' => 'Automations',
                'description' => 'Event-driven rules that open tasks, send notifications and propose reviewed actions.',
                'status' => 'available',
            ],
            [
                'slug' => 'ai-copilot',
                'name' => 'AI sales copilot',
                'description' => 'Drafts WhatsApp replies and order proposals from live conversation context, always awaiting approval.',
                'status' => 'available',
            ],
        ],
    ],
];
