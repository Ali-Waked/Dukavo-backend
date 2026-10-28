<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI providers and features
|--------------------------------------------------------------------------
|
| All AI configuration lives here so no controller, service or Vue build ever
| hardcodes a model name, an endpoint or a limit. The API keys are read from
| the server environment only and are never serialised to a response, a log line
| or the frontend bundle.
|
| `enabled` is the single master switch. When no provider has a key the feature
| is off and every AI endpoint answers with a clear 503 while the rest of Dukavo
| keeps working untouched.
|
| Providers are configured in blocks under `providers`, each naming a `driver`
| that the adapter factory knows how to build. `provider` selects the primary
| and `fallback_providers` lists the others in the order they may be tried when
| the primary cannot serve a request — a provider that lacks native schema
| enforcement, for instance.
|
*/

$xaiKey = env('GROK_API_KEY');
$openAiKey = env('OPENAI_API_KEY');

$transport = [
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
];

return [

    'enabled' => (bool) env('AI_ENABLED', filled($xaiKey) || filled($openAiKey)),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Every block is a driver plus its credential and endpoint. A block without
    | a key is simply never selected, so an installation can configure several
    | providers and use only the ones it has paid for.
    |
    */

    'provider' => env('AI_PROVIDER', 'xai'),

    'fallback_providers' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('AI_FALLBACK_PROVIDERS', 'openai'))),
        static fn (string $key): bool => $key !== '',
    )),

    'providers' => [
        'xai' => [
            'driver' => 'xai',
            'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
            'api_key' => $xaiKey,
            'path' => env('GROK_RESPONSES_PATH', '/responses'),
            'model' => env('GROK_MODEL', 'grok-4.7'),
            'transport' => $transport,
        ],
        'openai' => [
            'driver' => 'openai',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => $openAiKey,
            'path' => env('OPENAI_CHAT_PATH', '/chat/completions'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            // Most compatible endpoints only offer `json_object`; the schema is
            // then enforced by Dukavo's own validator, not by the provider.
            'native_schema' => (bool) env('OPENAI_NATIVE_SCHEMA', false),
            'transport' => $transport,
        ],
    ],

    // Default model for any feature that does not override it below. Aliased
    // names are recommended by xAI so deployments track the current release.
    'model' => env('GROK_MODEL', 'grok-4.7'),

    /*
    |--------------------------------------------------------------------------
    | Speech to text
    |--------------------------------------------------------------------------
    |
    | Voice notes use a dedicated transcription endpoint rather than the chat
    | provider. The adapter is OpenAI-compatible, so a self-hosted Whisper
    | gateway can be supplied with `base_url` and `path`. When no key is
    | configured the voice pipeline records a clear failure and never calls a
    | paid provider. Tests bind a fake provider instead of setting a key.
    |
    */

    'transcription' => [
        'enabled' => (bool) env('AI_TRANSCRIPTION_ENABLED', filled($openAiKey)),
        'provider' => env('AI_TRANSCRIPTION_PROVIDER', 'openai'),
        'base_url' => env('AI_TRANSCRIPTION_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
        'api_key' => env('AI_TRANSCRIPTION_API_KEY', $openAiKey),
        'path' => env('AI_TRANSCRIPTION_PATH', '/audio/transcriptions'),
        'model' => env('AI_TRANSCRIPTION_MODEL', 'whisper-1'),
        'response_format' => env('AI_TRANSCRIPTION_RESPONSE_FORMAT', 'verbose_json'),
        'language' => env('AI_TRANSCRIPTION_LANGUAGE'),
        'connect_timeout' => (int) env('AI_TRANSCRIPTION_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('AI_TRANSCRIPTION_TIMEOUT', 120),
    ],

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

    'transport' => $transport,

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | One entry per AI capability. `max_output_tokens` bounds the (billable)
    | completion size per feature; `daily_per_user` and `daily_per_tenant` are
    | usage caps enforced by {@see \App\Services\Ai\AiGuard}; `daily_tokens_*`
    | cap the volume rather than the count. A feature can be turned off
    | independently of the master switch.
    |
    | `repair_attempts` is how many times a completion that failed the
    | server-side schema check is sent back to the model with the errors
    | attached. It is capped at one by design: a second repair almost always
    | means the prompt itself is wrong.
    |
    */

    'features' => [
        'assistant' => [
            'enabled' => (bool) env('AI_FEATURE_ASSISTANT', true),
            'max_output_tokens' => (int) env('AI_ASSISTANT_MAX_TOKENS', 1200),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_ASSISTANT_DAILY_USER', 60),
            'daily_per_tenant' => (int) env('AI_ASSISTANT_DAILY_TENANT', 400),
            'daily_tokens_per_user' => (int) env('AI_ASSISTANT_DAILY_TOKENS_USER', 200000),
            'daily_tokens_per_tenant' => (int) env('AI_ASSISTANT_DAILY_TOKENS_TENANT', 2000000),
            'repair_attempts' => 1,
        ],
        'reply' => [
            'enabled' => (bool) env('AI_FEATURE_REPLY', true),
            'max_output_tokens' => (int) env('AI_REPLY_MAX_TOKENS', 900),
            'temperature' => 0.4,
            'daily_per_user' => (int) env('AI_REPLY_DAILY_USER', 80),
            'daily_per_tenant' => (int) env('AI_REPLY_DAILY_TENANT', 600),
            'daily_tokens_per_user' => (int) env('AI_REPLY_DAILY_TOKENS_USER', 250000),
            'daily_tokens_per_tenant' => (int) env('AI_REPLY_DAILY_TOKENS_TENANT', 2500000),
            'repair_attempts' => 1,
        ],
        'content' => [
            'enabled' => (bool) env('AI_FEATURE_CONTENT', true),
            'max_output_tokens' => (int) env('AI_CONTENT_MAX_TOKENS', 2000),
            'temperature' => 0.6,
            'daily_per_user' => (int) env('AI_FEATURE_CONTENT_DAILY_USER', 80),
            'daily_per_tenant' => (int) env('AI_FEATURE_CONTENT_DAILY_TENANT', 300),
            'daily_tokens_per_user' => (int) env('AI_CONTENT_DAILY_TOKENS_USER', 300000),
            'daily_tokens_per_tenant' => (int) env('AI_CONTENT_DAILY_TOKENS_TENANT', 1500000),
            'repair_attempts' => 1,
        ],
        'insights' => [
            'enabled' => (bool) env('AI_FEATURE_INSIGHTS', true),
            'max_output_tokens' => (int) env('AI_INSIGHTS_MAX_TOKENS', 1200),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_INSIGHTS_DAILY_USER', 40),
            'daily_per_tenant' => (int) env('AI_INSIGHTS_DAILY_TENANT', 200),
            'daily_tokens_per_user' => (int) env('AI_INSIGHTS_DAILY_TOKENS_USER', 150000),
            'daily_tokens_per_tenant' => (int) env('AI_INSIGHTS_DAILY_TOKENS_TENANT', 800000),
            'repair_attempts' => 1,
        ],
        'summary' => [
            'enabled' => (bool) env('AI_FEATURE_SUMMARY', true),
            'max_output_tokens' => (int) env('AI_SUMMARY_MAX_TOKENS', 1200),
            'temperature' => 0.3,
            'daily_per_user' => (int) env('AI_SUMMARY_DAILY_USER', 40),
            'daily_per_tenant' => (int) env('AI_SUMMARY_DAILY_TENANT', 200),
            'daily_tokens_per_user' => (int) env('AI_SUMMARY_DAILY_TOKENS_USER', 150000),
            'daily_tokens_per_tenant' => (int) env('AI_SUMMARY_DAILY_TOKENS_TENANT', 800000),
            'repair_attempts' => 1,
        ],
        'customer_summary' => [
            'enabled' => (bool) env('AI_FEATURE_CUSTOMER_SUMMARY', true),
            'max_output_tokens' => (int) env('AI_CUSTOMER_SUMMARY_MAX_TOKENS', 900),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_CUSTOMER_SUMMARY_DAILY_USER', 40),
            'daily_per_tenant' => (int) env('AI_CUSTOMER_SUMMARY_DAILY_TENANT', 200),
            'daily_tokens_per_user' => (int) env('AI_CUSTOMER_SUMMARY_DAILY_TOKENS_USER', 150000),
            'daily_tokens_per_tenant' => (int) env('AI_CUSTOMER_SUMMARY_DAILY_TOKENS_TENANT', 800000),
            'repair_attempts' => 1,
        ],
        'bundle_explanation' => [
            'enabled' => (bool) env('AI_FEATURE_BUNDLE_EXPLANATION', true),
            'max_output_tokens' => (int) env('AI_BUNDLE_EXPLANATION_MAX_TOKENS', 500),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_BUNDLE_EXPLANATION_DAILY_USER', 60),
            'daily_per_tenant' => (int) env('AI_BUNDLE_EXPLANATION_DAILY_TENANT', 400),
            'daily_tokens_per_user' => (int) env('AI_BUNDLE_EXPLANATION_DAILY_TOKENS_USER', 100000),
            'daily_tokens_per_tenant' => (int) env('AI_BUNDLE_EXPLANATION_DAILY_TOKENS_TENANT', 500000),
            'repair_attempts' => 1,
        ],
        'recovery' => [
            'enabled' => (bool) env('AI_FEATURE_RECOVERY', true),
            'max_output_tokens' => (int) env('AI_RECOVERY_MAX_TOKENS', 800),
            'temperature' => 0.4,
            'daily_per_user' => (int) env('AI_RECOVERY_DAILY_USER', 60),
            'daily_per_tenant' => (int) env('AI_RECOVERY_DAILY_TENANT', 400),
            'daily_tokens_per_user' => (int) env('AI_RECOVERY_DAILY_TOKENS_USER', 150000),
            'daily_tokens_per_tenant' => (int) env('AI_RECOVERY_DAILY_TOKENS_TENANT', 1500000),
            'repair_attempts' => 1,
        ],
        'sales_copilot' => [
            'enabled' => (bool) env('AI_FEATURE_SALES_COPILOT', true),
            'max_output_tokens' => (int) env('AI_SALES_COPILOT_MAX_TOKENS', 1400),
            'temperature' => 0.3,
            'daily_per_user' => (int) env('AI_SALES_COPILOT_DAILY_USER', 60),
            'daily_per_tenant' => (int) env('AI_SALES_COPILOT_DAILY_TENANT', 400),
            'daily_tokens_per_user' => (int) env('AI_SALES_COPILOT_DAILY_TOKENS_USER', 250000),
            'daily_tokens_per_tenant' => (int) env('AI_SALES_COPILOT_DAILY_TOKENS_TENANT', 2500000),
            'repair_attempts' => 1,
        ],
        'voice_transcription' => [
            'enabled' => (bool) env('AI_FEATURE_VOICE_TRANSCRIPTION', true),
            'daily_per_tenant' => (int) env('AI_VOICE_TRANSCRIPTION_DAILY_TENANT', 300),
        ],
        'voice_order' => [
            'enabled' => (bool) env('AI_FEATURE_VOICE_ORDER', true),
            'max_output_tokens' => (int) env('AI_VOICE_ORDER_MAX_TOKENS', 1400),
            'temperature' => 0.1,
            'daily_per_tenant' => (int) env('AI_VOICE_ORDER_DAILY_TENANT', 300),
            'daily_tokens_per_tenant' => (int) env('AI_VOICE_ORDER_DAILY_TOKENS_TENANT', 2000000),
            'repair_attempts' => 1,
        ],
        'merchant_copilot' => [
            'enabled' => (bool) env('AI_FEATURE_MERCHANT_COPILOT', true),
            'max_output_tokens' => (int) env('AI_MERCHANT_COPILOT_MAX_TOKENS', 1600),
            'temperature' => 0.2,
            'daily_per_user' => (int) env('AI_MERCHANT_COPILOT_DAILY_USER', 40),
            'daily_per_tenant' => (int) env('AI_MERCHANT_COPILOT_DAILY_TENANT', 200),
            'daily_tokens_per_user' => (int) env('AI_MERCHANT_COPILOT_DAILY_TOKENS_USER', 200000),
            'daily_tokens_per_tenant' => (int) env('AI_MERCHANT_COPILOT_DAILY_TOKENS_TENANT', 1000000),
            'repair_attempts' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | Bounds on the data a prompt may carry. The context builder refuses to
    | exceed the character ceiling, and the personal-data policy decides which
    | fields are minimised before anything leaves the installation.
    |
    */

    'context' => [
        'max_chars' => (int) env('AI_CONTEXT_MAX_CHARS', 12000),
        'max_fragment_chars' => (int) env('AI_CONTEXT_MAX_FRAGMENT_CHARS', 3000),
        'max_records' => (int) env('AI_CONTEXT_MAX_RECORDS', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Action governance
    |--------------------------------------------------------------------------
    |
    | AI never changes business data on its own. A proposal becomes a draft, a
    | draft is submitted, an authorised person approves or rejects it, and only
    | then is it executed — idempotently, so a retry cannot apply it twice.
    |
    | `require_separate_approver` refuses an approval from the person who made
    | the proposal. It suits teams with a second pair of eyes; a single-person
    | workspace leaves it off.
    |
    */

    'governance' => [
        'require_separate_approver' => (bool) env('AI_REQUIRE_SEPARATE_APPROVER', false),
        'idempotency_ttl_seconds' => (int) env('AI_IDEMPOTENCY_TTL', 86400),

        /*
         * Per-classification requirements. Executors declare the class; this
         * block decides how long a reviewed decision stays valid and whether
         * the approver must tick an explicit confirmation. Approval cannot be
         * removed from a classification that changes business data.
         */
        'classifications' => [
            'draft_creation' => [
                'approval_required' => true,
                'confirmation_required' => false,
                'expires_after_minutes' => (int) env('AI_ACTION_DRAFT_TTL_MINUTES', 1440),
            ],
            'reversible_change' => [
                'approval_required' => true,
                'confirmation_required' => true,
                'expires_after_minutes' => (int) env('AI_ACTION_REVERSIBLE_TTL_MINUTES', 720),
            ],
            'high_risk' => [
                'approval_required' => true,
                'confirmation_required' => true,
                'expires_after_minutes' => (int) env('AI_ACTION_HIGH_RISK_TTL_MINUTES', 120),
            ],
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
