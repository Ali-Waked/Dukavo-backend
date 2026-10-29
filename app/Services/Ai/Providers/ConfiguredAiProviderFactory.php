<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\OpenAi\OpenAiCompatibleProvider;
use App\Services\Ai\Providers\Xai\XaiProvider;

/**
 * Builds the adapters Dukavo ships with, from configuration.
 *
 * Credentials are read here and nowhere else: the adapter receives them, keeps
 * them, and the configuration array is never returned to a caller, a response
 * or a log line.
 */
final class ConfiguredAiProviderFactory implements AiProviderFactory
{
    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return ['xai', 'openai'];
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

        return match ($driver) {
            'xai' => new XaiProvider(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                path: $path !== '' ? $path : '/responses',
                defaultModel: $model,
                transport: $transport,
            ),
            'openai' => new OpenAiCompatibleProvider(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                path: $path !== '' ? $path : '/chat/completions',
                defaultModel: $model,
                transport: $transport,
                nativeSchema: (bool) ($config['native_schema'] ?? false),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown AI provider driver "%s".', $driver)),
        };
    }
}
