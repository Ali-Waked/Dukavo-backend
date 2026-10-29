<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\AiCapability;
use App\Support\Tenancy\TenantContext;

/**
 * The catalogue of AI use cases the application offers.
 *
 * Each use case is a separate class with its own prompt, its own schema and
 * its own rules. This registry is what makes that separation usable: it names
 * the use cases, states what each one needs from a provider, and says who may
 * use it. A new capability is added by writing one class and registering one
 * descriptor — nothing else in the application needs to change, and no
 * "everything AI" service ever grows.
 */
final class FeatureRegistry
{
    /**
     * @var array<string, FeatureDescriptor>
     */
    private array $descriptors = [];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Add a use case to the catalogue.
     */
    public function register(FeatureDescriptor $descriptor): void
    {
        $this->descriptors[$descriptor->key] = $descriptor;
    }

    public function has(string $key): bool
    {
        return isset($this->descriptors[$key]);
    }

    public function get(string $key): ?FeatureDescriptor
    {
        return $this->descriptors[$key] ?? null;
    }

    /**
     * @return array<string, FeatureDescriptor>
     */
    public function all(): array
    {
        return $this->descriptors;
    }

    /**
     * The use cases the given actor may actually use right now.
     *
     * A use case is available when it is switched on, the installation has a
     * provider that can serve it, and the actor holds the permission it
     * requires. This is what the frontend asks, so it never offers a button
     * that the backend will refuse.
     *
     * @return list<array{key: string, label: string, queueable: bool, enabled: bool}>
     */
    public function availableFor(User $actor, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenants?->tenant();

        $available = [];

        foreach ($this->descriptors as $descriptor) {
            $available[] = [
                'key' => $descriptor->key,
                'label' => $descriptor->label,
                'queueable' => $descriptor->queueable,
                'enabled' => $this->isEnabled($descriptor) && $this->isPermitted($actor, $descriptor, $tenant),
            ];
        }

        return $available;
    }

    /**
     * Whether a use case is switched on and servable by the provider.
     */
    public function isEnabled(FeatureDescriptor $descriptor): bool
    {
        $config = config("ai.features.{$descriptor->key}");

        if (! is_array($config) || ! ($config['enabled'] ?? false) || ! (bool) config('ai.enabled')) {
            return false;
        }

        return $this->providers->canServe(AiCapability::TextCompletion, ...$descriptor->capabilities);
    }

    private function isPermitted(User $actor, FeatureDescriptor $descriptor, ?Tenant $tenant): bool
    {
        foreach ($descriptor->permissions as $permission) {
            if ($actor->hasPermissionTo($permission, $tenant)) {
                return true;
            }
        }

        return $descriptor->permissions === [];
    }
}
