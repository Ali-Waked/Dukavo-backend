<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiTask;
use App\Models\AiProvider;
use App\Models\AiTaskRoute;
use App\Models\Tenant;
use App\Services\Ai\Support\AiCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Decides which provider serves a task, and in which order to fall back.
 *
 * This is the database-driven successor of the config-driven chain in
 * {@see ProviderRegistry}: the Platform Owner edits routes in the dashboard
 * rather than `.env`. The registry remains responsible for capability
 * matching; this class is responsible for *order* and for *ownership* — a
 * tenant never receives a route that is not theirs or the platform's.
 */
final class TaskRouter
{
    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    /**
     * The ordered provider chain for a task.
     *
     * Tenant routes win over platform routes only when BYOK is enabled for the
     * tenant AND the tenant has opted in; otherwise the platform chain is
     * used, exactly as before the tenant owned anything.
     *
     * @param  list<AiCapability>  $required
     * @return list<array{provider: AiProvider, model: ?string, source: string}>
     */
    public function chain(AiTask $task, ?Tenant $tenant, bool $byokEnabled, bool $tenantOptIn, array $required = []): array
    {
        $routes = $tenant !== null && $byokEnabled && $tenantOptIn
            ? $this->tenantRoutes($task, $tenant)
            : [];

        if ($routes === []) {
            $routes = $this->platformRoutes($task);
        }

        $chain = [];

        foreach ($routes as $route) {
            $provider = $route->provider()->with('platformCredential')->first();

            if (! $provider instanceof AiProvider || ! $provider->enabled) {
                continue;
            }

            if ($required !== [] && ! $this->modelSatisfies($route, $provider, $required)) {
                continue;
            }

            $chain[] = [
                'provider' => $provider,
                'model' => $route->model,
                'source' => $tenant !== null && $route->tenant_id === $tenant->getKey() ? 'tenant' : 'platform',
            ];
        }

        // No routes configured at all: fall back to the platform's default
        // chain (enabled providers by priority), so an unseeded installation
        // still answers.
        if ($chain === []) {
            foreach ($this->defaultProviders() as $provider) {
                $chain[] = [
                    'provider' => $provider,
                    'model' => null,
                    'source' => 'platform',
                ];
            }
        }

        return $chain;
    }

    /**
     * The platform's default chain: enabled providers, lowest priority first.
     *
     * @return list<AiProvider>
     */
    public function defaultProviders(): array
    {
        return AiProvider::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Platform routes for a task, primary first.
     *
     * @return list<AiTaskRoute>
     */
    private function platformRoutes(AiTask $task): array
    {
        return AiTaskRoute::query()
            ->whereNull('tenant_id')
            ->whereHas('task', fn ($query) => $query->where('key', $task->value))
            ->whereHas('provider', fn ($query) => $query->where('enabled', true))
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Tenant routes for a task — the merchant's own chain.
     *
     * A route only counts when the provider behind it has a credential the
     * tenant owns; a tenant route pointing at a platform-only provider is
     * dropped rather than silently using the platform's key.
     *
     * @return list<AiTaskRoute>
     */
    private function tenantRoutes(AiTask $task, Tenant $tenant): array
    {
        return AiTaskRoute::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereHas('task', fn ($query) => $query->where('key', $task->value))
            ->whereHas('provider', fn ($query) => $query->where('enabled', true))
            ->with('provider.credentials')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiTaskRoute $route): bool => $route->provider->credentials
                ->contains(fn ($credential): bool => $credential->tenant_id === $tenant->getKey()))
            ->values()
            ->all();
    }

    /**
     * Whether the routed model (or the provider's models) can serve the task.
     *
     * When the route names a model, that model must satisfy the requirement.
     * When it does not, any active model of the provider may.
     *
     * @param  list<AiCapability>  $required
     */
    private function modelSatisfies(AiTaskRoute $route, AiProvider $provider, array $required): bool
    {
        $modelKey = $route->model;

        if ($modelKey !== null && $modelKey !== '') {
            $model = $provider->models()
                ->where('key', $modelKey)
                ->where('is_active', true)
                ->first();

            return $model !== null && $model->satisfies($required);
        }

        return $provider->models()
            ->where('is_active', true)
            ->get()
            ->contains(fn ($model): bool => $model->satisfies($required));
    }
}
