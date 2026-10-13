<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Convenience wrappers over {@see PermissionService}.
 *
 * They resolve the service from the container so call sites never need the
 * tenant argument threaded through every method signature, and they read the
 * tenant from the active {@see TenantContext}.
 */
trait HasRoles
{
    public function assignRole(int $roleId, ?int $tenantId = null): void
    {
        $this->roleAssignments()->create([
            'role_id' => $roleId,
            'tenant_id' => $tenantId,
        ]);

        app(PermissionService::class)->flush($this);
    }

    public function revokeRole(int $roleId, ?int $tenantId = null): void
    {
        $this->roleAssignments()
            ->where('role_id', $roleId)
            ->where(fn ($query) => $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId))
            ->delete();

        app(PermissionService::class)->flush($this);
    }

    public function hasRole(string $slug, ?Tenant $tenant = null): bool
    {
        return app(PermissionService::class)->hasRole($this, $slug, $tenant ?? $this->currentTenant());
    }

    public function hasPermissionTo(string $permission, ?Tenant $tenant = null): bool
    {
        return app(PermissionService::class)->hasPermission($this, $permission, $tenant ?? $this->currentTenant());
    }

    /**
     * @return Collection<int, string>
     */
    public function permissionNames(?Tenant $tenant = null): Collection
    {
        return app(PermissionService::class)->permissionsFor($this, $tenant ?? $this->currentTenant());
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions, ?Tenant $tenant = null): bool
    {
        return app(PermissionService::class)->hasAnyPermission($this, $permissions, $tenant ?? $this->currentTenant());
    }

    public function isSuperAdmin(): bool
    {
        return app(PermissionService::class)->isSuperAdmin($this);
    }

    private function currentTenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }
}
