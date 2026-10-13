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
        ],
    ],
];
