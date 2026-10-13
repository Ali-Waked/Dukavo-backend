<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Enums\RoleScope;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionRegistry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating roles and assigning them, with the scope rules enforced in one place.
 *
 * The critical invariants this class owns:
 *  - a platform role assignment always has a NULL tenant_id;
 *  - a tenant role assignment always carries the tenant it is granted in;
 *  - a user can only be granted a role inside a tenant they belong to;
 *  - system roles cannot have their scope or permissions changed by a tenant.
 */
class RoleService
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @param  array<int, string>  $permissionSlugs
     */
    public function createRole(
        string $name,
        RoleScope $scope,
        ?string $description = null,
        array $permissionSlugs = [],
    ): Role {
        $slug = $this->uniqueSlug($name);

        return DB::transaction(function () use ($name, $slug, $scope, $description, $permissionSlugs): Role {
            $role = Role::query()->create([
                'name' => $name,
                'slug' => $slug,
                'scope' => $scope,
                'description' => $description,
                'is_system' => false,
            ]);

            $this->syncPermissions($role, $permissionSlugs);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array<int, string>  $attributes
     */
    public function updateRole(Role $role, array $attributes): Role
    {
        return DB::transaction(function () use ($role, $attributes): Role {
            if ($role->isSystem()) {
                // System roles are the contract every seed script and test
                // relies on; letting them be renamed or rescoped would silently
                // revoke access across the platform.
                unset($attributes['slug'], $attributes['scope']);
            }

            if (isset($attributes['name'])) {
                $attributes['slug'] ??= $role->slug;
            }

            if (array_key_exists('permissions', $attributes)) {
                $this->syncPermissions($role, $attributes['permissions']);
                unset($attributes['permissions']);
            }

            $role->fill($attributes)->save();

            return $role->load('permissions');
        });
    }

    public function deleteRole(Role $role): void
    {
        if ($role->isSystem()) {
            throw BusinessRuleViolation::because('System roles cannot be deleted.');
        }

        $role->delete();

        $this->permissions->flushRoleCatalogue();
    }

    /**
     * Replace the permission set of a role.
     *
     * System roles are locked: their permission set is the platform's contract
     * and is written by {@see PermissionSeeder} through the
     * model relationship, never through a request.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    public function syncPermissions(Role $role, array $permissionSlugs): Role
    {
        if ($role->isSystem()) {
            throw BusinessRuleViolation::because('Permissions of a system role cannot be changed.');
        }

        $this->assertKnownPermissions($permissionSlugs);

        $ids = Permission::query()
            ->whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($ids);
        $role->unsetRelation('permissions');

        $this->permissions->flushRoleCatalogue();

        return $role;
    }

    /**
     * @param  array<int, string>  $permissionSlugs
     */
    private function assertKnownPermissions(array $permissionSlugs): void
    {
        $unknown = array_values(array_diff($permissionSlugs, PermissionRegistry::slugs()));

        if ($unknown !== []) {
            throw BusinessRuleViolation::because('Unknown permissions: '.implode(', ', $unknown));
        }
    }

    /**
     * Grant a role. `$tenant` is required for tenant scoped roles and must be
     * NULL for platform roles — the two are never interchangeable.
     */
    public function assign(User $user, Role $role, ?Tenant $tenant = null): void
    {
        if ($role->scope === RoleScope::Platform) {
            $tenant = null;
        } else {
            if (! $tenant instanceof Tenant) {
                throw BusinessRuleViolation::because('A tenant is required to assign a tenant scoped role.');
            }

            if (! $user->isMemberOf($tenant->getKey())) {
                throw BusinessRuleViolation::because('The user is not a member of that tenant.');
            }
        }

        $user->roleAssignments()->updateOrCreate(
            ['role_id' => $role->getKey(), 'tenant_id' => $tenant?->getKey()],
            [],
        );

        $this->permissions->flush($user);
    }

    /**
     * @return array{role: string, tenant_id: int|null}
     */
    public function revoke(User $user, Role $role, ?Tenant $tenant = null): array
    {
        $tenantId = $role->scope === RoleScope::Platform ? null : $tenant?->getKey();

        $user->revokeRole($role->getKey(), $tenantId);

        return ['role' => $role->slug, 'tenant_id' => $tenantId];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
