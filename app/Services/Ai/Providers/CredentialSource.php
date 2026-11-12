<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\Tenant;

/**
 * Where a provider's live configuration comes from.
 *
 * The database is the source of truth once an operator has configured a
 * provider. Before that, the environment variables an operator may have set
 * historically still work, so an existing deployment keeps serving requests
 * without anyone touching the dashboard. Both paths are read server-side
 * only; a credential never travels back out of this class.
 */
final class CredentialSource
{
    /**
     * The effective configuration for one provider row, resolved for one
     * tenant: the tenant's own credential when BYOK is enabled and the tenant
     * opted in, otherwise the platform credential, otherwise the environment
     * bootstrap for that provider.
     *
     * @return array{api_key: ?string, base_url: string, model: ?string, source: string}|null
     */
    public function resolve(AiProvider $provider, ?Tenant $tenant, bool $byokEnabled, bool $tenantOptIn): ?array
    {
        $credential = $this->credential($provider, $tenant, $byokEnabled, $tenantOptIn);

        if ($credential !== null) {
            $key = $credential->decryptKey();

            if ($key !== null) {
                return [
                    'api_key' => $key,
                    'base_url' => $credential->base_url ?: $this->defaultBaseUrl($provider),
                    'model' => $this->credentialModel($credential),
                    'source' => $credential->owner()->value,
                ];
            }
        }

        return $this->fromEnvironment($provider);
    }

    /**
     * The platform credential for a provider, or null.
     */
    public function platformCredential(AiProvider $provider): ?AiProviderCredential
    {
        return $provider->platformCredential()
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * The credential to use: the tenant's own when BYOK is on and they opted
     * in, otherwise the platform's.
     */
    private function credential(AiProvider $provider, ?Tenant $tenant, bool $byokEnabled, bool $tenantOptIn): ?AiProviderCredential
    {
        if ($tenant !== null && $byokEnabled && $tenantOptIn) {
            $own = AiProviderCredential::query()
                ->where('provider_id', $provider->getKey())
                ->where('tenant_id', $tenant->getKey())
                ->whereNull('revoked_at')
                ->first();

            if ($own instanceof AiProviderCredential) {
                return $own;
            }
        }

        return $this->platformCredential($provider);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function credentialModel(?AiProviderCredential $credential): ?string
    {
        $config = $credential?->config;

        if (! is_array($config)) {
            return null;
        }

        $model = $config['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function defaultBaseUrl(AiProvider $provider): string
    {
        $entry = ProviderCatalog::entry($provider->key);

        return $entry['default_base_url'] ?? '';
    }

    /**
     * The historical environment bootstrap for a provider.
     *
     * An installation that configured xAI or OpenAI through `.env` before the
     * dashboard existed keeps working: the variables are read here and never
     * returned to a client. Providers without a bootstrap return null, which
     * reads as "not configured" everywhere it matters.
     *
     * @return array{api_key: ?string, base_url: string, model: ?string, source: string}|null
     */
    private function fromEnvironment(AiProvider $provider): ?array
    {
        $entry = ProviderCatalog::entry($provider->key);

        if ($entry === null) {
            return null;
        }

        $envKey = $entry['environment_key'];
        $key = env($envKey);

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $baseUrl = match ($provider->key) {
            'xai' => (string) env('GROK_BASE_URL', $entry['default_base_url']),
            'openai' => (string) env('OPENAI_BASE_URL', $entry['default_base_url']),
            'gemini' => (string) env('GEMINI_BASE_URL', $entry['default_base_url']),
            'groq' => (string) env('GROQ_BASE_URL', $entry['default_base_url']),
            'openrouter' => (string) env('OPENROUTER_BASE_URL', $entry['default_base_url']),
            'anthropic' => (string) env('ANTHROPIC_BASE_URL', $entry['default_base_url']),
            'ollama' => (string) env('OLLAMA_BASE_URL', $entry['default_base_url']),
            default => $entry['default_base_url'],
        };

        $model = match ($provider->key) {
            'xai' => env('GROK_MODEL'),
            'openai' => env('OPENAI_MODEL'),
            'gemini' => env('GEMINI_MODEL'),
            'groq' => env('GROQ_MODEL'),
            'openrouter' => env('OPENROUTER_MODEL'),
            'anthropic' => env('ANTHROPIC_MODEL'),
            'ollama' => env('OLLAMA_MODEL'),
            default => null,
        };

        return [
            'api_key' => $key,
            'base_url' => $baseUrl,
            'model' => is_string($model) && $model !== '' ? $model : $entry['default_model'],
            'source' => 'environment',
        ];
    }
}
