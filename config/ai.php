<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI (Grok / xAI)
|--------------------------------------------------------------------------
|
| All AI configuration lives here so no controller, service or Vue build ever
| hardcodes a model name, an endpoint or a limit. The API key is read from the
| server environment only and is never serialised to a response, a log line or
| the frontend bundle.
|
| `enabled` is the single master switch. When the key is absent the feature is
| off and every AI endpoint answers with a clear 503 while the rest of Dukavo
| keeps working untouched.
|
*/

$apiKey = env('GROK_API_KEY');

return [

    'enabled' => (bool) env('AI_ENABLED', filled($apiKey)),

    'provider' => env('AI_PROVIDER', 'xai'),

    'xai' => [
        // The Responses API is xAI's supported, non-legacy text endpoint.
        'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
        'api_key' => $apiKey,
        'path' => env('GROK_RESPONSES_PATH', '/responses'),
    ],

    // Default model for any feature that does not override it below. Aliased
    // names are recommended by xAI so deployments track the current release.
    'model' => env('GROK_MODEL', 'grok-4.7'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Timeouts, bounded retries and the maximum size of any single outbound
    | request. Backoff doubles, capped by `retry_max_ms`; only connection
    | failures, 429 and 5xx responses are retried.
    |
    */

    'transport' => [
        'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('AI_TIMEOUT', 60),
        'retry_times' => (int) env('AI_RETRY_TIMES', 2),
        'retry_base_ms' => (int) env('AI_RETRY_BASE_MS', 300),
        'retry_max_ms' => (int) env('AI_RETRY_MAX_MS', 4000),
        // Hard ceiling on the characters we will ever send in one prompt.
        'max_prompt_chars' => (int) env('AI_MAX_PROMPT_CHARS', 24000),
        // Per-identity requests per minute; a coarse burst guard layered on top
        // of the daily caps enforced in {@see \App\Services\Ai\AiGuard}.
        'rate_per_minute' => (int) env('AI_RATE_PER_MINUTE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | One entry per AI capability. `max_output_tokens` bounds the (billable)
    | completion size per feature; `daily_per_user` and `daily_per_tenant` are
    | usage caps enforced by {@see \App\Services\Ai\AiGuard}. A feature can be
    | turned off independently of the master switch.
    |
    */

    'features' => [
        'assistant' => [
            'enabled' => (bool) env('AI_FEATURE_ASSISTANT', true),
            'max_output_tokens' => (int) env('AI_ASSISTANT_MAX_TOKENS', 1200),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_ASSISTANT_DAILY_USER', 60),
            'daily_per_tenant' => (int) env('AI_ASSISTANT_DAILY_TENANT', 400),
        ],
        'reply' => [
            'enabled' => (bool) env('AI_FEATURE_REPLY', true),
            'max_output_tokens' => (int) env('AI_REPLY_MAX_TOKENS', 900),
            'temperature' => 0.4,
            'daily_per_user' => (int) env('AI_REPLY_DAILY_USER', 80),
            'daily_per_tenant' => (int) env('AI_REPLY_DAILY_TENANT', 600),
        ],
        'content' => [
            'enabled' => (bool) env('AI_FEATURE_CONTENT', true),
            'max_output_tokens' => (int) env('AI_CONTENT_MAX_TOKENS', 2000),
            'temperature' => 0.6,
            'daily_per_user' => (int) env('AI_CONTENT_DAILY_USER', 80),
            'daily_per_tenant' => (int) env('AI_CONTENT_DAILY_TENANT', 300),
        ],
        'insights' => [
            'enabled' => (bool) env('AI_FEATURE_INSIGHTS', true),
            'max_output_tokens' => (int) env('AI_INSIGHTS_MAX_TOKENS', 1200),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_INSIGHTS_DAILY_USER', 40),
            'daily_per_tenant' => (int) env('AI_INSIGHTS_DAILY_TENANT', 200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage ledger
    |--------------------------------------------------------------------------
    |
    | Metadata recorded for every call (never prompt or response content).
    | `retention_days` lets an operator prune old rows; nothing here stores
    | customer data.
    |
    */

    'usage' => [
        'table' => 'ai_usage',
        'retention_days' => (int) env('AI_USAGE_RETENTION_DAYS', 90),
    ],
];
