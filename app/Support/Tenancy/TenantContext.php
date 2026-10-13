<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use RuntimeException;

/**
 * Holds the tenant a request is currently acting inside.
 *
 * The context is populated exclusively by {@see ResolveTenant}
 * from data derived from the authenticated user (the `X-Tenant` header is only
 * ever treated as a *hint* and must be validated against the user's membership).
 * Nothing in the request payload may set it directly.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function has(): bool
    {
        return $this->tenant instanceof Tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /**
     * The current tenant, or a hard failure.
     *
     * Every tenant-scoped code path calls this, so a missing context surfaces
     * as a 400 instead of silently operating on unfiltered data.
     *
     * @throws RuntimeException
     */
    public function required(): Tenant
    {
        if (! $this->tenant instanceof Tenant) {
            throw new RuntimeException('No tenant has been resolved for the current request.');
        }

        return $this->tenant;
    }

    /**
     * Run a callback inside a tenant context, restoring the previous value
     * afterwards. Used by seeders, console commands and tests.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
