<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\Anthropic\AnthropicProvider;
use App\Services\Ai\Providers\Gemini\GeminiProvider;
use App\Services\Ai\Providers\OpenAi\OpenAiCompatibleProvider;
use App\Services\Ai\Providers\Xai\XaiProvider;

/**
 * Builds the adapters Dukavo ships with, from configuration.
 *
 * Credentials are read here and nowhere else: the adapter receives them, keeps
 * them, and the configuration array is never returned to a caller, a response
 * or a log line.
 *
 * `make()` serves the historical config-block path. `makeResolved()` serves
 * the credential-resolution path used by the routing engine, where the key,
 * endpoint and model come from the database (or the environment bootstrap)
 * rather than a static config array.
 */
final class ConfiguredAiProviderFactory implements AiProviderFactory
{
    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return ProviderCatalog::drivers();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $driver, array $config): AiProvider
    {
        $transport = (array) ($config['transport'] ?? []);
        $baseUrl = (string) ($config['base_url'] ?? '');
        $apiKey = is_string($config['api_key'] ?? null) ? (string) $config['api_key'] : null;
        $path = (string) ($config['path'] ?? '');
        $model = (string) ($config['model'] ?? '');

        return $this->build(
            driver: $driver,
            providerKey: (string) ($config['key'] ?? $driver),
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            model: $model,
            path: $path,
            transport: $transport,
            nativeSchema: (bool) ($config['native_schema'] ?? false),
        );
    }

    /**
     * Build an adapter from resolved credential material.
     *
     * This is the database-managed path: the credential resolver decides the
     * key, endpoint and model, and this method only maps them onto a driver.
     *
     * @param  array{api_key: ?string, base_url: string, model: ?string}  $credential
     * @param  array<string, mixed>  $transport
     */
    public function makeResolved(
        string $driver,
        string $providerKey,
        array $credential,
        array $transport = [],
    ): AiProvider {
        return $this->build(
            driver: $driver,
            providerKey: $providerKey,
            baseUrl: (string) ($credential['base_url'] ?? ''),
            apiKey: $credential['api_key'] ?? null,
            model: (string) ($credential['model'] ?? ''),
            path: '',
            transport: $transport,
            nativeSchema: (bool) (config('ai.providers.'.$providerKey.'.native_schema') ?? false),
        );
    }

    /**
     * @param  array{connect_timeout?: int, timeout?: int, retry_times?: int, retry_base_ms?: int, retry_max_ms?: int, max_prompt_chars?: int}  $transport
     */
    private function build(
        string $driver,
        string $providerKey,
        string $baseUrl,
        ?string $apiKey,
        string $model,
        string $path,
        array $transport,
        bool $nativeSchema,
    ): AiProvider {
        return match ($driver) {
            'xai' => new XaiProvider(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                path: $path !== '' ? $path : '/responses',
                defaultModel: $model,
                transport: $transport,
            ),
            'gemini' => new GeminiProvider(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                defaultModel: $model !== '' ? $model : 'gemini-2.0-flash',
                transport: $transport,
            ),
            'anthropic' => new AnthropicProvider(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                defaultModel: $model !== '' ? $model : 'claude-3-5-haiku-latest',
                transport: $transport,
            ),
            'openai_compatible', 'openai', 'groq', 'openrouter', 'ollama' => new OpenAiCompatibleProvider(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                path: $path !== '' ? $path : '/chat/completions',
                defaultModel: $model,
                transport: $transport,
                nativeSchema: $nativeSchema,
                providerKey: $providerKey,
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown AI provider driver "%s".', $driver)),
        };
    }
}
