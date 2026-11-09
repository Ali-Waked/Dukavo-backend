<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | FCM is a delivery channel only. Notification history always lives in
    | MySQL; nothing in the product reads notification state back from
    | Firebase. Credentials are supplied by the deployment environment and must
    | never be committed or exposed to the Vue or Flutter clients.
    |
    | `credentials` is an absolute path to the service-account JSON file.
    | `credentials_json` is an alternative for platforms that inject the whole
    | document (base64 or raw JSON) instead of mounting a file. The path wins
    | when both are present.
    |
    */

    'project_id' => env('FIREBASE_PROJECT_ID'),

    'credentials' => env('FIREBASE_CREDENTIALS'),

    'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),

    'enabled' => (bool) env('DUKAVO_NOTIFICATION_PUSH', false),

    'timeout' => (int) env('FIREBASE_HTTP_TIMEOUT', 10),

    /*
    | Android notification channels. The key is the event category used by the
    | notification record; the value is the channel created by the Flutter app.
    | Order, inventory, messaging, AI, security, report and announcement alerts
    | each get their own importance and sound so a merchant can tune them
    | independently in Android settings.
    */
    'android_channels' => [
        'orders' => 'orders',
        'inventory' => 'inventory',
        'messages' => 'messages',
        'ai' => 'ai',
        'automation' => 'ai',
        'approvals' => 'ai',
        'security' => 'security',
        'team' => 'security',
        'reports' => 'reports',
        'announcements' => 'announcements',
        'leads' => 'announcements',
        'tenants' => 'announcements',
        'default' => 'announcements',
    ],

];
