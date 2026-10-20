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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating roles and assigning them, with the scope rules enforced in one place.
 *
 * The critical invariants this class owns:
 *  - a platform role assignment always has a NULL tenant_id;
 *  - a tenant role assignment always carries the tenant it is granted in;
 *  - a custom tenant role is owned by exactly one tenant and can never be
 *    reached, edited or assigned from another;
 *  - a user can only be granted a role inside a tenant they belong to;
 *  - system roles are globally shared and cannot be renamed, rescoped,
 *    re-permissioned or deleted;
 *  - a caller may only grant permissions they themselves hold inside the
 *    target tenant, so a limited role cannot widen its own reach.
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
        ?Tenant $tenant = null,
        ?User $actor = null,
    ): Role {
        if ($scope === RoleScope::Platform && $tenant instanceof Tenant) {
            throw BusinessRuleViolation::because('A platform role cannot be owned by a tenant.');
        }

        if ($scope === RoleScope::Tenant && ! $tenant instanceof Tenant) {
            throw BusinessRuleViolation::because('A tenant role must be created inside a workspace.');
        }

        $slug = $this->uniqueSlug($name);

        return DB::transaction(function () use ($name, $slug, $scope, $description, $permissionSlugs, $tenant, $actor): Role {
            $role = Role::query()->create([
                'name' => $name,
                'slug' => $slug,
                'scope' => $scope,
                'description' => $description,
                'is_system' => false,
                'tenant_id' => $scope === RoleScope::Tenant ? $tenant?->getKey() : null,
            ]);

            $this->syncPermissions($role, $permissionSlugs, $actor, $tenant);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array<int, string>  $attributes
     */
    public function updateRole(Role $role, array $attributes, ?User $actor = null): Role
    {
        if ($role->isSystem()) {
            // System roles are the contract every seed script and test relies
            // on; letting them be renamed or rescoped would silently revoke
            // access across the platform. Only the seeder may touch them.
            throw BusinessRuleViolation::because('System roles cannot be modified.');
        }

        return DB::transaction(function () use ($role, $attributes, $actor): Role {
            // Scope is immutable: changing it would turn a workspace role into
            // a platform role (or the reverse), which is an escalation, never a
            // rename. It is refused by the request too; the service repeats the
            // guard because it is also called outside HTTP.
            unset($attributes['slug'], $attributes['scope'], $attributes['tenant_id'], $attributes['is_system']);

            if (array_key_exists('permissions', $attributes)) {
                $this->syncPermissions($role, $attributes['permissions'], $actor, $role->tenant);
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
     * and is written by {@see RolePermissionSeeder} through
     * the model relationship, never through a request.
     *
     * A caller may only grant permissions it already holds. That is the
     * server-side delegation policy: a role that was given `roles.manage` but
     * not, say, `products.delete` cannot mint a role that has it, so a limited
     * merchant role can never widen its own reach by proxy.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    public function syncPermissions(Role $role, array $permissionSlugs, ?User $actor = null, ?Tenant $tenant = null): Role
    {
        if ($role->isSystem()) {
            throw BusinessRuleViolation::because('Permissions of a system role cannot be changed.');
        }

        $this->assertKnownPermissions($permissionSlugs);

        if ($actor instanceof User) {
            $this->assertMayDelegate($actor, $permissionSlugs, $tenant);
        }

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
     * The delegation allowlist: a non-super-admin may only hand out permissions
     * it itself holds. Super admins hold the whole catalogue by definition.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    private function assertMayDelegate(User $actor, array $permissionSlugs, ?Tenant $tenant): void
    {
        if ($this->permissions->isSuperAdmin($actor)) {
            return;
        }

        $forbidden = array_values(array_filter(
            $permissionSlugs,
            fn (string $slug): bool => ! $this->permissions->hasPermission($actor, $slug, $tenant),
        ));

        if ($forbidden !== []) {
            throw new AuthorizationException(
                'You cannot grant permissions you do not hold: '.implode(', ', $forbidden),
            );
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

            // A custom role belongs to exactly one tenant. A NULL owner means a
            // global system template, which every tenant may hand out.
            if ($role->tenant_id !== null && $role->tenant_id !== $tenant->getKey()) {
                throw BusinessRuleViolation::because('The role belongs to another workspace.');
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
