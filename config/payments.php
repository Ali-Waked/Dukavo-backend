<?php

declare(strict_types=1);

use App\Services\Payments\Providers\TapPaymentProvider;

/*
|--------------------------------------------------------------------------
| Payment hub
|--------------------------------------------------------------------------
|
| Provider-independent configuration. Database rows decide which provider is
| enabled for which country; this file only maps a provider code to the adapter
| that talks to it and supplies transport settings.
|
| Secrets never live here. Tap merchant credentials are stored encrypted on
| `store_payment_accounts` and are only decrypted inside the adapter call.
|
*/

return [

    'default_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'tap'),

    /*
     * Which connection environment checkout is allowed to use. It defaults to
     * sandbox everywhere except production, so a developer's local installation
     * can never charge a live card by accident.
     */
    'checkout_environment' => env('PAYMENT_CHECKOUT_ENVIRONMENT', env('APP_ENV') === 'production' ? 'live' : 'sandbox'),

    /*
     * Where a customer returns after the provider redirects them. The tokens
     * are replaced by the session service; a browser redirect is only a hint to
     * re-open the order, never proof of payment.
     */
    'return_path' => env('PAYMENT_RETURN_PATH', '/shop/{tenant}/{store}/orders/{token}'),

    /*
     * How long a hosted checkout remains payable. Retrying after this point
     * creates a new attempt instead of reusing a session the provider may have
     * already expired. Zero disables the local expiry.
     */
    'session_ttl_minutes' => (int) env('PAYMENT_SESSION_TTL_MINUTES', 60),

    'providers' => [
        'tap' => [
            'adapter' => TapPaymentProvider::class,
            'base_url' => env('TAP_API_BASE_URL', 'https://api.tap.company/v2'),
            'timeout_seconds' => (int) env('TAP_TIMEOUT_SECONDS', 20),
            'connect_timeout_seconds' => (int) env('TAP_CONNECT_TIMEOUT_SECONDS', 10),
        ],
    ],

];
