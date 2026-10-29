<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Services\Ai\Support\AiCapability;

/**
 * What the application knows about one AI use case.
 *
 * The descriptor is the single description of a use case: what it is called,
 * who may use it, what the provider must be able to do, and whether it may be
 * run in the background. The frontend reads the same description through the
 * availability endpoint, so a capability the backend enforces and a button the
 * interface shows can never disagree.
 */
final readonly class FeatureDescriptor
{
    /**
     * @param  list<string>  $permissions
     * @param  list<AiCapability>  $capabilities
     * @param  class-string  $service  The concrete feature class.
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $permissions,
        public array $capabilities,
        public bool $queueable,
        public string $service,
    ) {}

    /**
     * The permission that must be held, if any.
     */
    public function primaryPermission(): ?string
    {
        return $this->permissions[0] ?? null;
    }
}
