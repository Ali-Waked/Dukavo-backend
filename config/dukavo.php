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
    | Platform identity
    |--------------------------------------------------------------------------
    |
    | These defaults are the deployment fallback for the General settings
    | category. An operator may override them in the Platform Settings
    | dashboard; the configuration applier writes the effective values back to
    | this block, so every service reads one shape whether the value came from
    | `.env` or the database.
    |
    */

    'platform' => [
        'description' => env('DUKAVO_DESCRIPTION', ''),
        'website_url' => env('DUKAVO_WEBSITE_URL', ''),
        'support_email' => env('DUKAVO_SUPPORT_EMAIL', ''),
        'contact_email' => env('DUKAVO_CONTACT_EMAIL', ''),
        'support_phone' => env('DUKAVO_SUPPORT_PHONE', ''),
        'logo_url' => env('DUKAVO_LOGO_URL', ''),
        'favicon_url' => env('DUKAVO_FAVICON_URL', ''),
        'social_x_url' => env('DUKAVO_SOCIAL_X_URL', ''),
        'social_linkedin_url' => env('DUKAVO_SOCIAL_LINKEDIN_URL', ''),
        'social_instagram_url' => env('DUKAVO_SOCIAL_INSTAGRAM_URL', ''),
        'social_facebook_url' => env('DUKAVO_SOCIAL_FACEBOOK_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Display formats
    |--------------------------------------------------------------------------
    |
    | Platform defaults only. A user's own language and a store's own currency
    | always win; these values are what a surface uses when nothing more
    | specific exists.
    |
    */

    'formats' => [
        'date' => env('DUKAVO_DATE_FORMAT', 'Y-m-d'),
        'time' => env('DUKAVO_TIME_FORMAT', 'H:i'),
        'week_starts_on' => (int) env('DUKAVO_WEEK_STARTS_ON', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security policy
    |--------------------------------------------------------------------------
    |
    | Safe application-level policy an operator may change at runtime. The
    | password block feeds {@see \Illuminate\Validation\Rules\Password}; the
    | login cap feeds the Fortify limiter. Infrastructure secrets are never
    | part of this block.
    |
    */

    'security' => [
        'password' => [
            'min_length' => (int) env('DUKAVO_PASSWORD_MIN_LENGTH', 12),
            'require_letters' => true,
            'require_mixed_case' => (bool) env('DUKAVO_PASSWORD_MIXED_CASE', true),
            'require_numbers' => (bool) env('DUKAVO_PASSWORD_NUMBERS', true),
            'require_symbols' => (bool) env('DUKAVO_PASSWORD_SYMBOLS', true),
            'require_uncompromised' => (bool) env('DUKAVO_PASSWORD_UNCOMPROMISED', true),
        ],
        'login_rate_limit_per_minute' => (int) env('DUKAVO_LOGIN_RATE_LIMIT', 5),
        'session_lifetime_minutes' => (int) env('DUKAVO_SESSION_LIFETIME', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform maintenance
    |--------------------------------------------------------------------------
    |
    | Application-level maintenance mode is deliberately separate from Laravel's
    | `artisan down`: the framework command writes a file and has no concept of
    | a platform role. The middleware reads this value, answers 503 for public
    | and tenant traffic, and keeps platform operators signed in so the switch
    | is always reversible from the dashboard.
    |
    */

    'maintenance' => [
        'enabled' => (bool) env('DUKAVO_MAINTENANCE_ENABLED', false),
        'message' => env('DUKAVO_MAINTENANCE_MESSAGE', 'Dukavo is undergoing scheduled maintenance. Please try again shortly.'),
        'retry_after_seconds' => (int) env('DUKAVO_MAINTENANCE_RETRY_AFTER', 3600),
    ],

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
        // The languages the platform can publish. A store enables a subset of
        // these for its own storefront; the translation assistant targets the
        // store's enabled list rather than a hardcoded pair.
        'supported' => [
            'en', 'ar', 'fr', 'es', 'de', 'it', 'pt', 'nl', 'tr', 'id',
            'pl', 'sv', 'da', 'ru', 'uk', 'fa', 'ur', 'he', 'hi', 'bn',
            'th', 'zh', 'ja', 'ko', 'el',
        ],
        'default' => 'en',
        // The languages a store starts with before a merchant enables more.
        // Kept small so the assistant does not offer twenty-five targets to a
        // shop that only ever publishes English and Arabic.
        'enabled_default' => ['en', 'ar'],
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
    | Identity, location and personal preferences
    |--------------------------------------------------------------------------
    |
    | These settings describe the person, never a store. They are used by the
    | first-login onboarding flow and the profile screen, and are deliberately
    | kept apart from the workspace block: changing a personal country, currency
    | or timezone must never touch a price, an order or a store setting.
    |
    | `geo.driver` is `headers` by default because it makes no outbound request
    | and cannot invent a location. A deployment behind Cloudflare, Vercel or a
    | reverse proxy that sets a country header gets a best-effort estimate; any
    | other deployment simply receives no IP-derived country. The `ipapi` driver
    | is opt-in and fails closed, so an unreachable lookup degrades to "not
    | detected" rather than a guessed value.
    |
    */

    'identity' => [
        // A safe, explicit fallback. Empty means "do not pretend to know".
        'default_country' => env('DUKAVO_DEFAULT_COUNTRY', ''),

        'onboarding' => [
            // Turning this off hides the prompt for every account. The profile
            // screen still works, so the setting is never a lock-out.
            'enabled' => (bool) env('DUKAVO_ONBOARDING_ENABLED', true),
        ],

        'geo' => [
            'driver' => env('DUKAVO_GEO_DRIVER', 'headers'),

            // Header names are matched case-insensitively by Symfony's request
            // header bag. The first non-empty value wins.
            'country_headers' => [
                'CF-IPCountry',
                'X-Vercel-IP-Country',
                'X-Geo-Country',
                'X-Country-Code',
            ],
            'city_headers' => [
                'CF-IPCity',
                'X-Vercel-IP-City',
                'X-Geo-City',
            ],

            'ipapi' => [
                'base_url' => env('DUKAVO_GEO_IPAPI_URL', 'https://ipapi.co'),
                'timeout_seconds' => (int) env('DUKAVO_GEO_TIMEOUT', 2),
                'cache_minutes' => (int) env('DUKAVO_GEO_CACHE_MINUTES', 1440),
            ],
        ],

        // A closed set so a stored pattern is always something the clients can
        // render without parsing arbitrary user input.
        'date_formats' => ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'],
        'time_formats' => ['12h', '24h'],

        'defaults' => [
            'theme' => 'system',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '24h',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication
    |--------------------------------------------------------------------------
    |
    | TOTP secrets and recovery-code hashes are encrypted at rest. A login that
    | passes the password step receives only a short-lived, single-use challenge
    | token; the real session token is minted after the second factor succeeds.
    |
    */

    'two_factor' => [
        'issuer' => env('DUKAVO_2FA_ISSUER', env('DUKAVO_NAME', 'Dukavo')),
        // Accepted clock drift, in 30-second steps.
        'window' => (int) env('DUKAVO_2FA_WINDOW', 1),
        'challenge_ttl_minutes' => (int) env('DUKAVO_2FA_CHALLENGE_TTL', 5),
        'max_attempts' => (int) env('DUKAVO_2FA_MAX_ATTEMPTS', 5),
        'recovery_codes' => (int) env('DUKAVO_2FA_RECOVERY_CODES', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account security history
    |--------------------------------------------------------------------------
    |
    | Security events are append-only and belong to the account holder. They
    | never contain passwords, tokens, TOTP secrets or recovery codes. The
    | retention window keeps the table bounded while remaining long enough for
    | a person to review an incident.
    |
    */

    'security_events' => [
        'retention_days' => (int) env('DUKAVO_SECURITY_EVENT_RETENTION_DAYS', 365),
        'per_page' => (int) env('DUKAVO_SECURITY_EVENT_PER_PAGE', 20),
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
            'push' => [
                'enabled' => (bool) env('DUKAVO_NOTIFICATION_PUSH', false),
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

        /*
         * How long a notification stays in the in-app centre. Archived rows are
         * removed first; read rows then age out. Unread operational alerts are
         * kept for the longer window so a merchant returning from holiday does
         * not lose an unpaid order or an out-of-stock warning.
         */
        'retention' => [
            'read_days' => (int) env('DUKAVO_NOTIFICATION_RETENTION_READ_DAYS', 180),
            'archived_days' => (int) env('DUKAVO_NOTIFICATION_RETENTION_ARCHIVED_DAYS', 30),
            'device_days' => (int) env('DUKAVO_NOTIFICATION_DEVICE_RETENTION_DAYS', 180),
        ],

        /*
         * Large platform broadcasts are paced so a single campaign cannot
         * exhaust the queue or trip Firebase rate limits.
         */
        'broadcast' => [
            'chunk_size' => (int) env('DUKAVO_NOTIFICATION_BROADCAST_CHUNK', 250),
            'per_minute' => (int) env('DUKAVO_NOTIFICATION_BROADCAST_PER_MINUTE', 600),
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
            rtrim((string) env('APP_URL', 'http://localhost'), '/').'/images/email/dukavo-logo-en.png',
        ),
        // Mail clients do not reliably apply dark mode, so each recipient
        // language uses its light artwork. English falls back to `logo_url`
        // above when no dedicated override is configured.
        'logo_urls' => [
            'en' => env('DUKAVO_EMAIL_LOGO_URL_EN'),
            'ar' => env(
                'DUKAVO_EMAIL_LOGO_URL_AR',
                rtrim((string) env('APP_URL', 'http://localhost'), '/').'/images/email/dukavo-logo-ar.png',
            ),
        ],
        'logo_width' => (int) env('DUKAVO_EMAIL_LOGO_WIDTH', 168),
        'webhook_secret' => env('DUKAVO_EMAIL_WEBHOOK_SECRET'),
        'support_email' => env('DUKAVO_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
        // How long a marketing unsubscribe link remains usable. A token is
        // single-purpose and hashed at rest; zero means it never expires.
        'unsubscribe_ttl_days' => (int) env('DUKAVO_EMAIL_UNSUBSCRIBE_TTL_DAYS', 90),
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

        /*
         * The public capability catalogue.
         *
         * `category` groups the feature on the public page; `availability`
         * distinguishes a released capability from one that still needs a
         * provider account, from one that is only partly wired, from one that
         * is not released. `requires_configuration` is the merchant-facing
         * truth: a capability can exist in the code and still need credentials
         * or a platform switch before a merchant can use it. `note` is the
         * one-line caveat shown beside the badge.
         */
        'features' => [
            /* catalogue */
            [
                'slug' => 'products',
                'category' => 'catalogue',
                'name' => 'Products & variants',
                'description' => 'Products with variants, SKU-level pricing, images, categories and draft, active or archived states.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'inventory',
                'category' => 'catalogue',
                'name' => 'Inventory',
                'description' => 'Per-store stock, reservations, low-stock thresholds and a movement ledger that explains every quantity.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'stock-transfers',
                'category' => 'catalogue',
                'name' => 'Stock transfers',
                'description' => 'Move stock between stores with paired ledger entries, so both locations stay accurate.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'bundles',
                'category' => 'catalogue',
                'name' => 'Product bundles',
                'description' => 'Group products into bundles with their own discount and availability preview.',
                'status' => 'available',
                'availability' => 'partial',
                'requires_configuration' => false,
                'note' => 'Bundles can be prepared and priced today; bundle lines are not yet part of storefront checkout.',
            ],

            /* sales */
            [
                'slug' => 'orders',
                'category' => 'sales',
                'name' => 'Orders',
                'description' => 'Order lifecycle from pending to delivered, with payment states, totals and a full status history.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'customers',
                'category' => 'sales',
                'name' => 'Customers & CRM',
                'description' => 'Customer profiles with addresses, channel identities, order history and conversation context.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'conversations',
                'category' => 'sales',
                'name' => 'Shared inbox',
                'description' => 'One inbox for every customer conversation, assigned to the right person and tied to the customer it belongs to.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'recovery',
                'category' => 'sales',
                'name' => 'Lost-sales recovery',
                'description' => 'Surface abandoned opportunities and follow up with an AI-assisted draft the team approves.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],

            /* channels */
            [
                'slug' => 'whatsapp',
                'category' => 'channels',
                'name' => 'WhatsApp Business',
                'description' => 'Connect a WhatsApp Business account and reply from the shared inbox, with templates when the 24-hour window closes.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires a Meta WhatsApp Business account and approved credentials.',
            ],
            [
                'slug' => 'instagram',
                'category' => 'channels',
                'name' => 'Instagram Messaging',
                'description' => 'Bring Instagram professional-account messages into the same inbox as every other channel.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires a Meta app with Instagram Messaging permissions and App Review.',
            ],
            [
                'slug' => 'messenger',
                'category' => 'channels',
                'name' => 'Facebook Messenger',
                'description' => 'Handle Messenger conversations beside WhatsApp, Instagram and email without switching tools.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires a Meta app with pages_messaging permissions and App Review.',
            ],
            [
                'slug' => 'telegram',
                'category' => 'channels',
                'name' => 'Telegram',
                'description' => 'Connect a Telegram bot and manage customer messages, media and voice notes in the shared inbox.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires a Telegram bot token.',
            ],
            [
                'slug' => 'email-channel',
                'category' => 'channels',
                'name' => 'Email channel',
                'description' => 'Receive customer email as a conversation and reply from the same place as your chat channels.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Uses the platform mail transport and a sender address.',
            ],
            [
                'slug' => 'sms',
                'category' => 'channels',
                'name' => 'SMS',
                'description' => 'A generic SMS gateway channel for markets where text messaging is the primary customer channel.',
                'status' => 'coming_soon',
                'availability' => 'coming_soon',
                'requires_configuration' => true,
                'note' => 'Awaiting a configured SMS gateway before it can be enabled.',
            ],

            /* storefront */
            [
                'slug' => 'storefront',
                'category' => 'storefront',
                'name' => 'Public storefront',
                'description' => 'A public shop per store with catalogue, product pages, policies, contact details and multilingual content.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Enabled per store with its own slug and storefront settings.',
            ],
            [
                'slug' => 'guest-checkout',
                'category' => 'storefront',
                'name' => 'Guest checkout',
                'description' => 'Customers can order without creating an account; Dukavo records the customer and reserves stock.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Available once the storefront and at least one payment method are configured.',
            ],
            [
                'slug' => 'public-cart',
                'category' => 'storefront',
                'name' => 'Storefront cart',
                'description' => 'Shoppers collect items in a cart, adjust quantities against live stock and continue to guest checkout.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'The cart is client-side and revalidated at checkout; prices and availability are always confirmed by the server.',
            ],
            [
                'slug' => 'order-tracking',
                'category' => 'storefront',
                'name' => 'Order tracking',
                'description' => 'An unguessable tracking link shows the customer their order status and timeline without exposing other data.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'payments',
                'category' => 'storefront',
                'name' => 'Payments',
                'description' => 'Cash on delivery, bank transfer and online card payments through a connected payment provider.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Online card payments require an approved Tap merchant account; cash and bank transfer work without one.',
            ],
            [
                'slug' => 'shipping',
                'category' => 'storefront',
                'name' => 'Shipping zones',
                'description' => 'City and area based delivery zones with flat fees, free-shipping thresholds and localized delivery instructions.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Zones and fees are configured per store; carrier integrations are not included.',
            ],

            /* ai */
            [
                'slug' => 'ai-copilot',
                'category' => 'ai',
                'name' => 'AI sales copilot',
                'description' => 'Drafts replies and order proposals from live conversation context, with catalogue and stock checks.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires an AI provider configured for the platform. Suggestions never send or change anything on their own.',
            ],
            [
                'slug' => 'ai-summary',
                'category' => 'ai',
                'name' => 'Conversation & customer summaries',
                'description' => 'A short read on what the customer wants, what was discussed and what should happen next.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires an AI provider; summaries are read-only assistance.',
            ],
            [
                'slug' => 'ai-translation',
                'category' => 'ai',
                'name' => 'AI-assisted translation',
                'description' => 'Generate translations for products, categories, store content and other translatable fields, then review and approve them.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'AI produces a suggestion; only an approved translation is published, and source edits mark it stale.',
            ],
            [
                'slug' => 'ai-insights',
                'category' => 'ai',
                'name' => 'AI business briefing',
                'description' => 'A plain-language summary of dashboard figures, highlights and recommended next steps.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Requires an AI provider and enough dashboard data to summarise.',
            ],
            [
                'slug' => 'ai-providers',
                'category' => 'ai',
                'name' => 'Provider orchestration',
                'description' => 'AI tasks route across configured providers with health checks, fallback and per-feature usage limits.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Configured by the platform administrator; usage limits are enforced per feature and workspace.',
            ],

            /* automation */
            [
                'slug' => 'automation',
                'category' => 'automation',
                'name' => 'Automation templates',
                'description' => 'Install reviewed workflows for order follow-up, low stock, abandoned orders, welcome messages and team handoffs.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Templates install paused; a merchant reviews triggers and actions before enabling them.',
            ],
            [
                'slug' => 'automation-rules',
                'category' => 'automation',
                'name' => 'Custom automation rules',
                'description' => 'Build rules from triggers, conditions and safe actions such as notifying the team, creating a task or proposing an AI action.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],

            /* insights */
            [
                'slug' => 'analytics',
                'category' => 'insights',
                'name' => 'Analytics',
                'description' => 'Revenue, order and stock dashboards over the last 30 days, with the trends that need attention.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'profit-intelligence',
                'category' => 'insights',
                'name' => 'Profit intelligence',
                'description' => 'See margin and profit signals beside revenue, with exportable views for deeper analysis.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'inventory-intelligence',
                'category' => 'insights',
                'name' => 'Inventory intelligence',
                'description' => 'Identify slow-moving stock, dead stock and reorder priorities before cash gets stuck on a shelf.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],

            /* workspace */
            [
                'slug' => 'team',
                'category' => 'workspace',
                'name' => 'Team & permissions',
                'description' => 'Merchant and staff roles with per-module permissions, scoped to each business.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'multi-store',
                'category' => 'workspace',
                'name' => 'Multiple stores',
                'description' => 'Run several stores under one tenant, each with its own inventory, currency, timezone and storefront.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
            [
                'slug' => 'translations',
                'category' => 'workspace',
                'name' => 'Multilingual content',
                'description' => 'Translate catalogue, storefront and Help Center content, with approval, revisions and stale-copy protection.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Enable the store locales you need; only approved translations are served publicly.',
            ],
            [
                'slug' => 'notifications',
                'category' => 'workspace',
                'name' => 'Notifications',
                'description' => 'In-app and email notifications with per-user preferences and a consent record.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => true,
                'note' => 'Push delivery requires Firebase credentials; in-app and email work without them.',
            ],
            [
                'slug' => 'mobile',
                'category' => 'workspace',
                'name' => 'Mobile app',
                'description' => 'A Flutter app for shopping, orders, payment retry, notifications, profile and the Help Center.',
                'status' => 'available',
                'availability' => 'partial',
                'requires_configuration' => false,
                'note' => 'Customer shopping and account flows are available; some merchant back-office screens remain web-first.',
            ],
            [
                'slug' => 'help-center',
                'category' => 'workspace',
                'name' => 'Help Center',
                'description' => 'A public and signed-in knowledge base with categories, search, visibility tiers and article feedback.',
                'status' => 'available',
                'availability' => 'available',
                'requires_configuration' => false,
                'note' => null,
            ],
        ],
    ],
];
