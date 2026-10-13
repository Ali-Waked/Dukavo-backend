<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The permission catalogue and the system roles.
 *
 * This seeder is the only place allowed to write permissions onto a system role:
 * {@see RoleService::syncPermissions()} refuses that change for
 * every other caller, which is what stops a tenant admin from silently widening
 * their own powers. It is idempotent, so it is safe to re-run on an existing
 * database — new registry entries appear, removed ones are revoked.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->permissions();
        $this->systemRoles();

        foreach (Role::query()->where('is_system', true)->get() as $role) {
            $role->permissions()->sync(
                Permission::query()
                    ->whereIn('slug', PermissionRegistry::defaultsFor($role->scope, $role->slug))
                    ->pluck('id'),
            );
        }
    }

    /**
     * Create the system roles if they are missing, and keep their names and
     * descriptions current.
     */
    private function systemRoles(): void
    {
        foreach (self::definitions() as $definition) {
            Role::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ],
            );
        }
    }

    /**
     * Create or refresh every permission in the registry.
     *
     * @return Collection<int, Permission>
     */
    private function permissions(): Collection
    {
        $permissions = collect();

        foreach (PermissionRegistry::all() as $definition) {
            $permissions->push(Permission::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'module' => $definition['module'],
                    'description' => $definition['description'],
                ],
            ));
        }

        // A permission removed from the registry must lose its grants, otherwise a
        // stale slug would keep authorising something nobody declares any more.
        Permission::query()
            ->whereNotIn('slug', PermissionRegistry::slugs())
            ->each(function (Permission $permission): void {
                $permission->roles()->detach();
                $permission->delete();
            });

        return $permissions;
    }

    /**
     * The system roles, created if absent.
     *
     * Exposed as a static helper so a test can build a role set without running
     * the whole seeder.
     *
     * @return list<array{slug: string, name: string, scope: RoleScope, description: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'super-admin',
                'name' => 'Super Admin',
                'scope' => RoleScope::Platform,
                'description' => 'Unrestricted access to Dukavo itself, including every merchant.',
            ],
            [
                'slug' => 'dukavo-admin',
                'name' => 'Platform Support',
                'scope' => RoleScope::Platform,
                'description' => 'Provisions and suspends tenants; cannot change roles.',
            ],
            [
                'slug' => 'merchant',
                'name' => 'Merchant',
                'scope' => RoleScope::Tenant,
                'description' => 'Full control of one merchant workspace.',
            ],
            [
                'slug' => 'staff',
                'name' => 'Staff',
                'scope' => RoleScope::Tenant,
                'description' => 'Works orders and customers without access to settings.',
            ],
        ];
    }
}
