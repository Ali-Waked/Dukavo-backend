<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleScope;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\RoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role ownership and privilege boundaries.
 *
 * The old role table was global, so a custom role minted by one merchant was
 * visible and assignable by every other merchant. These cover the correction:
 * a custom role belongs to exactly one workspace, scope cannot be flipped to
 * escalate, and the two privileges that would let an operator widen its own
 * reach — granting the super administrator role, or granting permissions the
 * caller does not hold — are refused server-side.
 */
class RoleTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $merchantA;

    private User $merchantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenantA = $this->tenantWithStore();
        $this->tenantB = $this->tenantWithStore();
        $this->merchantA = $this->memberOf($this->tenantA, 'merchant');
        $this->merchantB = $this->memberOf($this->tenantB, 'merchant');
    }

    public function test_a_custom_role_is_invisible_to_another_tenant(): void
    {
        $role = $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');

        $listed = $this->actingIn($this->merchantB, $this->tenantB)
            ->getJson('/api/v1/tenant/roles')
            ->assertOk()
            ->json('data');

        $this->assertNotContains($role->getKey(), array_column($listed, 'id'));

        $this->actingIn($this->merchantB, $this->tenantB)
            ->getJson("/api/v1/tenant/roles/{$role->getKey()}")
            ->assertNotFound();
    }

    public function test_a_custom_role_cannot_be_assigned_in_another_tenant(): void
    {
        $role = $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');
        $member = $this->memberOf($this->tenantB, 'staff');

        $this->actingIn($this->merchantB, $this->tenantB)
            ->postJson("/api/v1/tenant/roles/{$role->getKey()}/assign", ['user_id' => $member->getKey()])
            ->assertNotFound();

        $this->assertDatabaseMissing('user_roles', [
            'role_id' => $role->getKey(),
            'user_id' => $member->getKey(),
        ]);
    }

    public function test_another_tenants_role_cannot_be_reassigned_onto_a_member(): void
    {
        $foreign = $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');
        $staff = $this->memberOf($this->tenantB, 'staff');
        $currentRole = $staff->tenantRoleAssignments($this->tenantB->getKey())->firstOrFail();

        $this->actingIn($this->merchantB, $this->tenantB)
            ->patchJson("/api/v1/tenant/users/{$staff->getKey()}", ['role_id' => $foreign->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $staff->getKey(),
            'role_id' => $currentRole->role_id,
            'tenant_id' => $this->tenantB->getKey(),
        ]);
    }

    public function test_a_role_scope_cannot_be_escalated_by_update(): void
    {
        $role = $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');

        $this->actingIn($this->merchantA, $this->tenantA)
            ->patchJson("/api/v1/tenant/roles/{$role->getKey()}", [
                'name' => 'Warehouse Lead',
                'scope' => RoleScope::Platform->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.scope', RoleScope::Tenant->value);

        $this->assertSame(RoleScope::Tenant, $role->fresh()->scope);
    }

    public function test_a_tenant_role_cannot_be_minted_without_a_workspace(): void
    {
        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/platform/roles', [
                'name' => 'Global Thing',
                'scope' => RoleScope::Tenant->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->assertDatabaseMissing('roles', ['name' => 'Global Thing']);
    }

    public function test_the_platform_catalogue_excludes_tenant_owned_roles(): void
    {
        $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');
        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/roles')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['name' => 'Warehouse']);
    }

    public function test_a_system_role_cannot_be_renamed(): void
    {
        $merchantRole = Role::query()->where('slug', 'merchant')->firstOrFail();

        $this->actingIn($this->merchantA, $this->tenantA)
            ->patchJson("/api/v1/tenant/roles/{$merchantRole->getKey()}", ['name' => 'Owner'])
            ->assertStatus(409);

        $this->assertSame('Merchant', $merchantRole->fresh()->name);
    }

    public function test_a_limited_role_cannot_grant_permissions_it_lacks(): void
    {
        $limited = app(RoleService::class)->createRole(
            'Role Manager',
            RoleScope::Tenant,
            'Can shape roles but little else.',
            ['roles.view', 'roles.manage'],
            $this->tenantA,
        );

        $actor = $this->memberWithRole($this->tenantA, $limited);
        $target = $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');

        $this->actingIn($actor, $this->tenantA)
            ->putJson("/api/v1/tenant/roles/{$target->getKey()}/permissions", [
                'permissions' => ['roles.view', 'products.delete'],
            ])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->permissions->contains('slug', 'products.delete'));
    }

    public function test_an_ordinary_platform_admin_cannot_grant_the_super_admin_role(): void
    {
        $admin = $this->platformUser('dukavo-admin');
        $superRole = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $target = $this->platformUser('dukavo-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/users/{$target->getKey()}/roles/{$superRole->getKey()}")
            ->assertForbidden();

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $target->getKey(),
            'role_id' => $superRole->getKey(),
        ]);
    }

    public function test_an_ordinary_platform_admin_cannot_create_a_super_admin(): void
    {
        $admin = $this->platformUser('dukavo-admin');
        $superRole = Role::query()->where('slug', 'super-admin')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Rogue Super',
                'email' => 'rogue-super@dukavo.test',
                'password' => 'very-strong-password',
                'password_confirmation' => 'very-strong-password',
                'role_id' => $superRole->getKey(),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rogue-super@dukavo.test']);
    }

    public function test_only_an_owner_may_add_another_owner(): void
    {
        $manager = app(RoleService::class)->createRole(
            'Team Manager',
            RoleScope::Tenant,
            'Runs the team but owns nothing.',
            ['dashboard.view', 'users.view', 'users.manage'],
            $this->tenantA,
        );

        $nonOwner = $this->memberWithRole($this->tenantA, $manager);

        $this->actingIn($nonOwner, $this->tenantA)
            ->postJson('/api/v1/tenant/users', [
                'name' => 'Second Owner',
                'email' => 'second-owner@urban-basket.test',
                'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->getKey(),
                'is_owner' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_owner');

        $this->assertDatabaseMissing('users', ['email' => 'second-owner@urban-basket.test']);
    }

    public function test_an_owner_may_add_another_owner(): void
    {
        $this->actingIn($this->merchantA, $this->tenantA)
            ->postJson('/api/v1/tenant/users', [
                'name' => 'Co Owner',
                'email' => 'co-owner@urban-basket.test',
                'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->getKey(),
                'is_owner' => true,
            ])
            ->assertCreated();
    }

    public function test_revoking_a_role_takes_effect_immediately(): void
    {
        $role = $this->createCustomRole($this->merchantA, $this->tenantA, 'Viewer', ['products.view']);
        $member = $this->memberWithRole($this->tenantA, $role);

        // Establishes the cached permission set for the member.
        $this->actingIn($member, $this->tenantA)
            ->getJson('/api/v1/tenant/products')
            ->assertOk();

        $this->actingIn($this->merchantA, $this->tenantA)
            ->postJson("/api/v1/tenant/roles/{$role->getKey()}/revoke", ['user_id' => $member->getKey()])
            ->assertOk();

        // The revoke must flush the cache, not wait for its TTL to lapse.
        $this->actingIn($member, $this->tenantA)
            ->getJson('/api/v1/tenant/products')
            ->assertForbidden();
    }

    public function test_deleting_a_role_takes_effect_immediately(): void
    {
        $role = $this->createCustomRole($this->merchantA, $this->tenantA, 'Viewer', ['products.view']);
        $member = $this->memberWithRole($this->tenantA, $role);

        $this->actingIn($member, $this->tenantA)
            ->getJson('/api/v1/tenant/products')
            ->assertOk();

        $this->actingIn($this->merchantA, $this->tenantA)
            ->deleteJson("/api/v1/tenant/roles/{$role->getKey()}")
            ->assertNoContent();

        // Deleting the role flushes the global catalogue, so the still-cached
        // assignment no longer maps to any permission.
        $this->actingIn($member, $this->tenantA)
            ->getJson('/api/v1/tenant/products')
            ->assertForbidden();
    }

    public function test_a_system_role_cannot_be_deleted_or_re_permissioned(): void
    {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        $this->actingIn($this->merchantA, $this->tenantA)
            ->deleteJson("/api/v1/tenant/roles/{$staffRole->getKey()}")
            ->assertStatus(409);

        $this->actingIn($this->merchantA, $this->tenantA)
            ->putJson("/api/v1/tenant/roles/{$staffRole->getKey()}/permissions", [
                'permissions' => ['roles.manage'],
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('roles', ['id' => $staffRole->getKey()]);
        $this->assertFalse($staffRole->fresh()->permissions->contains('slug', 'roles.manage'));
    }

    public function test_a_tenant_admin_cannot_delete_another_tenants_role(): void
    {
        $role = $this->createCustomRole($this->merchantA, $this->tenantA, 'Warehouse');

        $this->actingIn($this->merchantB, $this->tenantB)
            ->deleteJson("/api/v1/tenant/roles/{$role->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseHas('roles', ['id' => $role->getKey()]);
    }

    public function test_a_platform_operator_can_create_a_platform_role(): void
    {
        $operator = $this->platformUser('dukavo-admin');

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/platform/roles', [
                'name' => 'Billing Admin',
                'scope' => RoleScope::Platform->value,
                'permissions' => ['roles.view'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', RoleScope::Platform->value)
            ->assertJsonPath('data.tenant_id', null);
    }

    private function createCustomRole(
        User $actor,
        Tenant $tenant,
        string $name,
        array $permissions = ['dashboard.view'],
    ): Role {
        $id = $this->actingIn($actor, $tenant)
            ->postJson('/api/v1/tenant/roles', [
                'name' => $name,
                'scope' => RoleScope::Tenant->value,
                'permissions' => $permissions,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $tenant->getKey())
            ->json('data.id');

        return Role::query()->findOrFail($id);
    }

    private function memberWithRole(Tenant $tenant, Role $role): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->getKey(), ['is_owner' => false]);
        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);

        return $user->fresh();
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug);
    }
}
