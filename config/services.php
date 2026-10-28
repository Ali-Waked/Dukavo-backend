<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta)
    |--------------------------------------------------------------------------
    |
    | Platform-level channel settings, read through `config()` from exactly one
    | place: {@see \App\Services\Channels\WhatsApp\MetaCloudGateway} and the
    | webhook controller. Nothing here is ever serialised into an API resource,
    | a log line or the SPA bundle.
    |
    | `cloud_api_token`, `webhook_verify_token` and `app_secret` are secrets and
    | stay empty until a developer fills them in locally. The per-merchant copy
    | of a token lives encrypted on the `whatsapp_accounts` row; the token here
    | only backs the single number named by `phone_number_id`, which is how a
    | fresh installation exercises the channel before every tenant stores its
    | own credential.
    |
    | `app_secret` is what validates `X-Hub-Signature-256` on incoming webhook
    | deliveries. It is a different value from `webhook_verify_token` (the GET
    | handshake) and from `cloud_api_token` (outgoing authorisation); treating
    | them as interchangeable is the classic way to break this integration.
    |
    */

    'whatsapp' => [
        'cloud_api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v26.0'),
        'cloud_api_token' => env('WHATSAPP_CLOUD_API_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'api_base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
        'messaging_window_hours' => (int) env('WHATSAPP_MESSAGING_WINDOW_HOURS', 24),
        'media_max_bytes' => (int) env('WHATSAPP_MEDIA_MAX_BYTES', 25 * 1024 * 1024),
        'media_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'WHATSAPP_MEDIA_HOSTS',
            'lookaside.fbsbx.com,*.fbcdn.net,*.whatsapp.net',
        ))))),
        'voice_media_retention_days' => (int) env('WHATSAPP_VOICE_MEDIA_RETENTION_DAYS', 30),
    ],

];
