<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Support\AiCapability;

/**
 * What the application knows about a provider driver, before any credential.
 *
 * This is the catalogue the management UI is built from: every driver the
 * factory can build, the canonical name an operator sees, the capabilities
 * the adapter itself declares, and the driver's default endpoint. A provider
 * with no credential is listed as "not configured" — never as connected.
 *
 * `environment_key` names the bootstrap variable an operator may set instead
 * of storing the key in the database (see {@see CredentialSource}). It is
 * only ever read server-side.
 */
final class ProviderCatalog
{
    /**
     * One entry per driver the application can build.
     *
     * @return list<array{
     *     key: string,
     *     driver: string,
     *     name: string,
     *     capabilities: list<AiCapability>,
     *     default_base_url: string,
     *     default_path: string,
     *     default_model: string,
     *     environment_key: string,
     *     notes: string,
     * }>
     */
    public static function entries(): array
    {
        return [
            [
                'key' => 'gemini',
                'driver' => 'gemini',
                'name' => 'Google Gemini',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                    AiCapability::NativeSchema,
                    AiCapability::ToolCalls,
                    AiCapability::ImageInput,
                ],
                'default_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'default_path' => '/models/{model}:generateContent',
                'default_model' => 'gemini-2.0-flash',
                'environment_key' => 'GEMINI_API_KEY',
                'notes' => 'Google AI Studio / Vertex key. Free tier is rate limited, not unlimited.',
            ],
            [
                'key' => 'groq',
                'driver' => 'groq',
                'name' => 'Groq',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                    AiCapability::NativeSchema,
                    AiCapability::ToolCalls,
                ],
                'default_base_url' => 'https://api.groq.com/openai/v1',
                'default_path' => '/chat/completions',
                'default_model' => 'llama-3.3-70b-versatile',
                'environment_key' => 'GROQ_API_KEY',
                'notes' => 'OpenAI-compatible endpoint; JSON mode with schema support.',
            ],
            [
                'key' => 'openrouter',
                'driver' => 'openrouter',
                'name' => 'OpenRouter',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                    AiCapability::NativeSchema,
                    AiCapability::ToolCalls,
                    AiCapability::ImageInput,
                ],
                'default_base_url' => 'https://openrouter.ai/api/v1',
                'default_path' => '/chat/completions',
                'default_model' => 'openai/gpt-4o-mini',
                'environment_key' => 'OPENROUTER_API_KEY',
                'notes' => 'Routes to many upstream models; model ids carry the vendor prefix.',
            ],
            [
                'key' => 'xai',
                'driver' => 'xai',
                'name' => 'xAI Grok',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                    AiCapability::NativeSchema,
                    AiCapability::ToolCalls,
                ],
                'default_base_url' => 'https://api.x.ai/v1',
                'default_path' => '/responses',
                'default_model' => 'grok-4.7',
                'environment_key' => 'GROK_API_KEY',
                'notes' => 'Responses API with `store: false` so prompts are not retained.',
            ],
            [
                'key' => 'openai',
                'driver' => 'openai_compatible',
                'name' => 'OpenAI',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                    AiCapability::ToolCalls,
                    AiCapability::ImageInput,
                ],
                'default_base_url' => 'https://api.openai.com/v1',
                'default_path' => '/chat/completions',
                'default_model' => 'gpt-4o-mini',
                'environment_key' => 'OPENAI_API_KEY',
                'notes' => 'Chat Completions with JSON mode; strict schema on supported models.',
            ],
            [
                'key' => 'anthropic',
                'driver' => 'anthropic',
                'name' => 'Anthropic Claude',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                    AiCapability::NativeSchema,
                    AiCapability::ToolCalls,
                    AiCapability::ImageInput,
                ],
                'default_base_url' => 'https://api.anthropic.com/v1',
                'default_path' => '/messages',
                'default_model' => 'claude-3-5-haiku-latest',
                'environment_key' => 'ANTHROPIC_API_KEY',
                'notes' => 'Messages API; schemas travel as tool definitions.',
            ],
            [
                'key' => 'ollama',
                'driver' => 'ollama',
                'name' => 'Ollama (self-hosted)',
                'capabilities' => [
                    AiCapability::TextCompletion,
                    AiCapability::StructuredOutput,
                ],
                'default_base_url' => 'http://127.0.0.1:11434/v1',
                'default_path' => '/chat/completions',
                'default_model' => 'llama3.1',
                'environment_key' => 'OLLAMA_API_KEY',
                'notes' => 'Local, OpenAI-compatible adapter for future self-hosted use; no key required for localhost.',
            ],
        ];
    }

    /**
     * The entry for one provider key, or null when the key is unknown.
     *
     * @return array{key: string, driver: string, name: string, capabilities: list<AiCapability>, default_base_url: string, default_path: string, default_model: string, environment_key: string, notes: string}|null
     */
    public static function entry(string $key): ?array
    {
        foreach (self::entries() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The driver keys the factory can build.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return array_values(array_unique(array_column(self::entries(), 'driver')));
    }

    /**
     * Whether a base URL is safe to call.
     *
     * Provider endpoints are operator input. This refuses non-HTTP schemes,
     * `localhost`, literal loopback / private / reserved IPs and missing
     * hosts, so a provider row can never be turned into an SSRF probe of the
     * installation's own network. Public hostnames are allowed: refusing
     * them would break every legitimate provider.
     */
    public static function isSafeBaseUrl(string $baseUrl): bool
    {
        $trimmed = trim($baseUrl);

        if ($trimmed === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = (string) parse_url($trimmed, PHP_URL_HOST);

        if ($host === '' || strcasecmp($host, 'localhost') === 0) {
            return false;
        }

        // A hostname is fine — it resolves to a public provider. A literal
        // IP must not be loopback, private or reserved, which is exactly what
        // the two flags assert.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
