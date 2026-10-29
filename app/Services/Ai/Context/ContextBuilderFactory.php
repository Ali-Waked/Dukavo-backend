<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\User;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;

/**
 * Creates a context builder for one actor.
 *
 * A builder accumulates the fragments of a single prompt, so it cannot be a
 * shared instance: two requests would otherwise see each other's data. The
 * factory is the shared, stateless part; the builder it hands out belongs to
 * one use case and one actor.
 */
final class ContextBuilderFactory
{
    public function __construct(
        private readonly PromptGuard $prompts,
        private readonly TenantContext $tenants,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * A builder whose permission checks are made as this actor.
     */
    public function for(User $actor): ContextBuilder
    {
        return new ContextBuilder($this->prompts, $this->tenants, $this->permissions, $actor);
    }

    /**
     * A builder for a server-owned background task.
     *
     * There is no actor to authorise, so the builder is used only with the
     * tenant boundary and data explicitly supplied by the caller. It must never
     * be used for a request-shaped feature that needs permission checks.
     */
    public function forSystem(): ContextBuilder
    {
        return new ContextBuilder($this->prompts, $this->tenants, $this->permissions, null);
    }
}
