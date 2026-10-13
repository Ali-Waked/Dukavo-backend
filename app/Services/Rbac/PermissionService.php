<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves what a user may do, and inside which tenant.
 *
 * This is the only place role assignments are interpreted. Both the HTTP layer
 * (middleware, policies) and the UI payload (`GET /api/v1/auth/me`) read from
 * here, so the permissions the frontend renders are provably the same set the
 * backend enforces.
 *
 * Caching strategy: a user's role assignments (a handful of rows) are cached
 * per user, while the role → permission map is cached globally. Permission
 * checks then happen in PHP with zero queries, which keeps a permission
 * middleware check from becoming a database round trip on every request.
 */
class PermissionService
{
    private const CACHE_VERSION = 'v1';

    private const TTL_SECONDS = 300;

    /**
     * Roles effective for the user inside the given tenant: their platform
     * roles plus the roles assigned for that specific tenant.
     *
     * @return Collection<int, Role>
     */
    public function rolesFor(User $user, ?Tenant $tenant): Collection
    {
        $roleIds = $this->roleIdsFor($user, $tenant);

        if ($roleIds === []) {
            return new Collection;
        }

        return Role::query()->whereKey($roleIds)->get();
    }

    /**
     * @return Collection<int, string>
     */
    public function permissionsFor(User $user, ?Tenant $tenant): Collection
    {
        $roleIds = $this->roleIdsFor($user, $tenant);

        if ($roleIds === []) {
            return new Collection;
        }

        $permissionsByRole = $this->permissionsByRole();

        return collect($roleIds)
            ->flatMap(fn (int $roleId): array => $permissionsByRole[$roleId] ?? [])
            ->unique()
            ->values();
    }

    public function hasPermission(User $user, string $permission, ?Tenant $tenant): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->permissionsFor($user, $tenant)->contains($permission);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(User $user, array $permissions, ?Tenant $tenant): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->permissionsFor($user, $tenant)->intersect($permissions)->isNotEmpty();
    }

    public function hasRole(User $user, string $roleSlug, ?Tenant $tenant): bool
    {
        return $this->rolesFor($user, $tenant)->contains('slug', $roleSlug);
    }

    public function hasRoleInTenant(User $user, string $roleSlug, int $tenantId): bool
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->hasRole($user, $roleSlug, $tenant);
    }

    /**
     * Platform roles ignore tenant context entirely: they are how Dukavo staff
     * administer the SaaS itself.
     */
    public function hasPlatformRole(User $user, string $roleSlug): bool
    {
        return $this->hasRole($user, $roleSlug, null);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->hasPlatformRole($user, 'super-admin');
    }

    public function isPlatformUser(User $user): bool
    {
        return $this->roleIdsFor($user, null) !== [];
    }

    /**
     * The two system roles that administer Dukavo itself: the super admin and
     * the support admin. This is the single test for "may this caller act on
     * platform scoped data", so the platform route gate, role minting and role
     * assignment can never drift apart on which roles count as operators.
     */
    public function isPlatformOperator(User $user): bool
    {
        return $this->isSuperAdmin($user)
            || $this->hasPlatformRole($user, 'dukavo-admin');
    }

    /**
     * Invalidate a user's cached assignments after a role is granted or revoked.
     */
    public function flush(User $user): void
    {
        Cache::forget($this->userCacheKey($user));
    }

    /**
     * Invalidate the role → permission map after role permissions change.
     */
    public function flushRoleCatalogue(): void
    {
        Cache::forget($this->catalogueCacheKey());
    }

    /**
     * Tenant ids the user holds any role assignment in.
     *
     * @return list<int>
     */
    public function tenantIdsFor(User $user): array
    {
        return collect($this->assignments($user))
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function roleIdsFor(User $user, ?Tenant $tenant): array
    {
        $assignments = $this->assignments($user);

        if ($assignments === []) {
            return [];
        }

        $tenantId = $tenant?->getKey();

        return collect($assignments)
            ->filter(fn (array $assignment): bool => $assignment['tenant_id'] === null
                || ($tenantId !== null && $assignment['tenant_id'] === $tenantId))
            ->pluck('role_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Role assignments as plain arrays rather than models: the cache only ever
     * needs two columns, and keeping serialised Eloquent models out of the
     * cache avoids stale attribute snapshots being hydrated later.
     *
     * @return list<array{role_id: int, tenant_id: int|null}>
     */
    private function assignments(User $user): array
    {
        return Cache::remember(
            $this->userCacheKey($user),
            self::TTL_SECONDS,
            fn (): array => UserRole::query()
                ->where('user_id', $user->getKey())
                ->get(['role_id', 'tenant_id'])
                ->map(fn (UserRole $assignment): array => [
                    'role_id' => (int) $assignment->role_id,
                    'tenant_id' => $assignment->tenant_id === null ? null : (int) $assignment->tenant_id,
                ])
                ->all(),
        );
    }

    /**
     * @return array<int, list<string>>
     */
    private function permissionsByRole(): array
    {
        return Cache::remember(
            $this->catalogueCacheKey(),
            self::TTL_SECONDS,
            fn (): array => Role::query()
                ->with('permissions:id,slug')
                ->get()
                ->mapWithKeys(fn (Role $role): array => [
                    $role->getKey() => $role->permissions->pluck('slug')->all(),
                ])
                ->all(),
        );
    }

    private function userCacheKey(User $user): string
    {
        return sprintf('rbac:%s:assignments:%d', self::CACHE_VERSION, $user->getKey());
    }

    private function catalogueCacheKey(): string
    {
        return sprintf('rbac:%s:catalogue', self::CACHE_VERSION);
    }
}
