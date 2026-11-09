<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | The server process that terminates WebSocket connections. `php artisan
    | reverb:start` reads this entry. It is deliberately separate from the
    | `broadcasting` connection: the app publishes to Reverb over HTTP while the
    | server holds the long-lived sockets.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST', 'localhost'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | One application is enough for Dukavo: all merchants connect to the same
    | Reverb instance and channel authorization keeps them apart. The key and
    | secret must match the `reverb` broadcasting connection above.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST', '127.0.0.1'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => array_filter(array_map(
                    'trim',
                    explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '')),
                )),
                'ping_interval' => env('REVERB_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_ACTIVITY_TIMEOUT', 30),
                'max_message_size' => env('REVERB_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

];
