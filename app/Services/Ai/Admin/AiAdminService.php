<?php

declare(strict_types=1);

namespace App\Services\Ai\Admin;

use App\Enums\AiTask as AiTaskEnum;
use App\Models\AiPromptTemplate;
use App\Models\AiPromptVersion;
use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\AiTask;
use App\Models\AiTaskRoute;
use App\Models\Tenant;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Ai\Providers\CircuitBreaker;
use App\Services\Ai\Providers\ConfiguredAiProviderFactory;
use App\Services\Ai\Providers\CredentialSource;
use App\Services\Ai\Providers\ProviderCatalog;
use Illuminate\Support\Facades\DB;

/**
 * The platform owner's AI administration.
 *
 * Everything the AI admin dashboard does to provider rows, credentials,
 * routing and prompts goes through here, so the invariants live in one place:
 * keys are encrypted before they touch the database, only masked hints ever
 * leave it, base URLs are refused unless they are safe to call, and routing
 * changes are applied as a whole chain rather than row-by-row so a task is
 * never left with half a chain.
 */
final class AiAdminService
{
    public function __construct(
        private readonly CredentialSource $credentials,
        private readonly AiProviderFactory $factory,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * Register a known provider from the catalogue, with its default models.
     *
     * Idempotent: registering an existing key returns the row unchanged, so
     * "add provider" in the dashboard can be retried safely.
     */
    public function registerCatalogProvider(string $key, array $overrides = []): AiProvider
    {
        $entry = ProviderCatalog::entry($key);

        if ($entry === null) {
            throw new \InvalidArgumentException(sprintf('Unknown provider key [%s].', $key));
        }

        return DB::transaction(function () use ($entry, $overrides): AiProvider {
            $provider = AiProvider::query()->firstOrNew(['key' => $entry['key']]);

            $provider->fill([
                'name' => $overrides['name'] ?? $entry['name'],
                'driver' => $entry['driver'],
                'enabled' => (bool) ($overrides['enabled'] ?? $provider->enabled ?? true),
                'priority' => (int) ($overrides['priority'] ?? $provider->priority ?? 100),
                'notes' => $overrides['notes'] ?? $provider->notes ?? $entry['notes'],
            ]);

            $provider->save();

            if (! $provider->models()->exists()) {
                $provider->models()->create([
                    'key' => $entry['default_model'],
                    'display_name' => $entry['default_model'],
                    'capabilities' => array_map(
                        static fn ($capability): string => $capability->value,
                        $entry['capabilities'],
                    ),
                    'is_active' => true,
                    'is_default' => true,
                ]);
            }

            return $provider->refresh();
        });
    }

    /**
     * Seed every task the platform routes, idempotently.
     *
     * The catalogue of tasks comes from the {@see AiTaskEnum} enum, so
     * seeding cannot drift from what the engine accepts.
     *
     * @return int How many task rows were created.
     */
    public function seedTasks(): int
    {
        $created = 0;

        foreach (AiTaskEnum::cases() as $task) {
            $exists = AiTask::query()->where('key', $task->value)->exists();

            if (! $exists) {
                AiTask::query()->create([
                    'key' => $task->value,
                    'label' => $this->taskLabel($task),
                    'enabled' => true,
                    'description' => $task->legacyFeatureKey() !== null
                        ? 'Serves the legacy "'.$task->legacyFeatureKey().'" use cases.'
                        : null,
                ]);

                $created++;
            }
        }

        return $created;
    }

    /**
     * Store or rotate a provider credential, encrypting the key at rest.
     *
     * The plaintext key is accepted here and never returned, never logged and
     * never written anywhere except the encrypted column. `key_hint` keeps
     * only the last characters so the dashboard can show "••••••••abcd".
     *
     * @param  array{base_url?: string|null, model?: string|null}  $options
     */
    public function storeCredential(
        AiProvider $provider,
        string $apiKey,
        ?int $tenantId = null,
        array $options = [],
    ): AiProviderCredential {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new \InvalidArgumentException('The API key cannot be empty.');
        }

        $baseUrl = isset($options['base_url']) && is_string($options['base_url']) && trim($options['base_url']) !== ''
            ? trim($options['base_url'])
            : null;

        if ($baseUrl !== null && ! ProviderCatalog::isSafeBaseUrl($baseUrl)) {
            throw new \InvalidArgumentException('The base URL is not a safe provider endpoint.');
        }

        return DB::transaction(function () use ($provider, $apiKey, $tenantId, $baseUrl, $options): AiProviderCredential {
            $credential = AiProviderCredential::query()
                ->where('provider_id', $provider->getKey())
                ->where('tenant_id', $tenantId)
                ->firstOrNew();

            $config = is_array($credential->config) ? $credential->config : [];

            if (isset($options['model']) && is_string($options['model'])) {
                $config['model'] = $options['model'];
            }

            $credential->fill([
                'provider_id' => $provider->getKey(),
                'tenant_id' => $tenantId,
                'api_key' => encrypt($apiKey),
                'key_hint' => substr($apiKey, -4),
                'base_url' => $baseUrl,
                'config' => $config,
                'revoked_at' => null,
            ]);

            $credential->save();

            return $credential;
        });
    }

    /**
     * Revoke a credential. Revoked credentials are never deleted: the audit
     * trail keeps the fact of the change, and the row stops being used the
     * moment `revoked_at` is set.
     */
    public function revokeCredential(AiProviderCredential $credential): AiProviderCredential
    {
        $credential->forceFill(['revoked_at' => now()])->save();

        return $credential->refresh();
    }

    /**
     * Test one provider's live credential without spending real budget on a
     * chat completion where a cheaper check exists.
     *
     * Returns only reachability facts; the key itself never travels. A test
     * failure does not open the circuit breaker — an operator's explicit test
     * is information, not traffic.
     *
     * @return array{ok: bool, status: int, latency_ms: int, reason?: string}
     */
    public function testConnection(AiProvider $provider, ?Tenant $tenant = null): array
    {
        // A platform operator tests the platform credential; a tenant tests
        // its own row only, so a merchant's check can never silently pass
        // against the platform's key.
        $credential = $tenant !== null
            ? $this->tenantCredentialMaterial($provider, $tenant)
            : $this->credentials->resolve($provider, tenant: null, byokEnabled: false, tenantOptIn: false);

        if ($credential === null) {
            return ['ok' => false, 'status' => 0, 'latency_ms' => 0, 'reason' => 'not_configured'];
        }

        $adapter = $this->factory instanceof ConfiguredAiProviderFactory
            ? $this->factory->makeResolved($provider->driver, $provider->key, $credential, (array) config('ai.transport', []))
            : $this->factory->make($provider->driver, [
                'key' => $provider->key,
                'base_url' => $credential['base_url'],
                'api_key' => $credential['api_key'],
                'model' => $credential['model'],
            ]);

        // Connection testing is an admin concern, not part of the serving
        // contract, so adapters advertise it as an extra method; an adapter
        // without one (e.g. a test fake) reports "unsupported" rather than
        // failing the request.
        if (! method_exists($adapter, 'testConnection')) {
            return ['ok' => false, 'status' => 0, 'latency_ms' => 0, 'reason' => 'unsupported'];
        }

        try {
            $result = $adapter->testConnection();
        } catch (\Throwable) {
            // A transport-level failure (DNS, TLS, timeout) is a failed
            // check, not a failed dashboard request.
            $result = ['ok' => false, 'status' => 0, 'latency_ms' => 0, 'reason' => 'connection'];
        }

        $this->breaker->recordCheck(
            $provider,
            (bool) $result['ok'],
            (int) ($result['latency_ms'] ?? 0),
            (string) ($result['status'] ?? 'unknown'),
        );

        return $result;
    }

    /**
     * The tenant's own credential material, resolved without any fallback:
     * a tenant-side connection test must exercise the tenant's key or
     * nothing, never the platform's.
     *
     * @return array{api_key: string, base_url: string, model: ?string}|null
     */
    private function tenantCredentialMaterial(AiProvider $provider, Tenant $tenant): ?array
    {
        $row = AiProviderCredential::query()
            ->where('provider_id', $provider->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->whereNull('revoked_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $apiKey = $row->decryptKey();

        if ($apiKey === null) {
            return null;
        }

        $config = is_array($row->config) ? $row->config : [];
        $model = $config['model'] ?? null;
        $entry = ProviderCatalog::entry($provider->key);
        $baseUrl = (string) ($row->base_url ?: ($entry['default_base_url'] ?? ''));

        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'model' => is_string($model) && $model !== '' ? $model : null,
        ];
    }

    /**
     * Replace a task's platform routing chain in one transaction.
     *
     * The chain is applied whole, so a task is never left mid-edit: either
     * the new chain is in place or the old one still is.
     *
     * @param  list<array{provider_id: int, model?: string|null, priority?: int, is_fallback?: bool, max_output_tokens?: int|null, temperature?: float|null, timeout_seconds?: int, max_retries?: int}>  $routes
     * @return list<AiTaskRoute>
     */
    public function syncRoutes(AiTask $task, array $routes): array
    {
        return DB::transaction(function () use ($task, $routes): array {
            AiTaskRoute::query()
                ->where('task_id', $task->getKey())
                ->whereNull('tenant_id')
                ->delete();

            $created = [];

            foreach (array_values($routes) as $index => $route) {
                $provider = AiProvider::query()->find($route['provider_id']);

                if ($provider === null) {
                    throw new \InvalidArgumentException(sprintf('Unknown provider [%s].', $route['provider_id']));
                }

                $created[] = AiTaskRoute::query()->create([
                    'task_id' => $task->getKey(),
                    'provider_id' => $provider->getKey(),
                    'tenant_id' => null,
                    'model' => $route['model'] ?? null,
                    'priority' => (int) ($route['priority'] ?? ($index + 1) * 10),
                    'is_fallback' => $index > 0 || (bool) ($route['is_fallback'] ?? false),
                    'max_output_tokens' => $route['max_output_tokens'] ?? null,
                    'temperature' => $route['temperature'] ?? null,
                    'timeout_seconds' => (int) ($route['timeout_seconds'] ?? 0),
                    'max_retries' => (int) ($route['max_retries'] ?? 0),
                ]);
            }

            return $created;
        });
    }

    /**
     * Create the next immutable version of a prompt template and, optionally,
     * activate it. Activation is a pointer move; no version row is edited.
     *
     * @param  list<string>  $variables
     */
    public function createPromptVersion(
        AiPromptTemplate $template,
        string $locale,
        string $systemPrompt,
        ?string $userPrompt,
        array $variables,
        ?int $createdBy = null,
        ?string $changeNote = null,
        bool $activate = true,
    ): AiPromptVersion {
        return DB::transaction(function () use ($template, $locale, $systemPrompt, $userPrompt, $variables, $createdBy, $changeNote, $activate): AiPromptVersion {
            $version = AiPromptVersion::query()->create([
                'template_id' => $template->getKey(),
                'version' => (int) AiPromptVersion::query()->where('template_id', $template->getKey())->max('version') + 1,
                'locale' => $locale,
                'system_prompt' => $systemPrompt,
                'user_prompt' => $userPrompt,
                'variables' => array_values(array_unique($variables)),
                'created_by' => $createdBy,
                'change_note' => $changeNote,
                'created_at' => now(),
            ]);

            if ($activate) {
                $template->forceFill(['active_version_id' => $version->getKey()])->save();
            }

            return $version;
        });
    }

    private function taskLabel(AiTaskEnum $task): string
    {
        return match ($task) {
            AiTaskEnum::Translation => 'Translation',
            AiTaskEnum::ProductDescription => 'Product description',
            AiTaskEnum::ProductContent => 'Product content',
            AiTaskEnum::CategoryContent => 'Category content',
            AiTaskEnum::MarketingContent => 'Marketing content',
            AiTaskEnum::SeoGeneration => 'SEO generation',
            AiTaskEnum::ConversationAnalysis => 'Conversation analysis',
            AiTaskEnum::ConversationSummary => 'Conversation summary',
            AiTaskEnum::WhatsAppAssistant => 'WhatsApp assistant',
            AiTaskEnum::CustomerReplySuggestion => 'Customer reply suggestion',
            AiTaskEnum::SalesAnalysis => 'Sales analysis',
            AiTaskEnum::InventoryInsights => 'Inventory insights',
            AiTaskEnum::GeneralTextGeneration => 'General text generation',
        };
    }
}
