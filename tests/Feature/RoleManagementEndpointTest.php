<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tenant's role endpoints: sync permissions, assign and revoke.
 *
 * The risk these cover is not a wrong shape but a wrong grant. Route model
 * binding resolves a role by id alone, so an endpoint that reaches a platform
 * role from inside a tenant would let a merchant edit Dukavo's own roles — or,
 * through `assign`, grant themselves one. The boundary is asserted first and
 * the payload second.
 */
class RoleManagementEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_GUARD = 'Platform roles can only be managed by Dukavo platform administrators.';

    private Tenant $tenant;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_syncing_permissions_replaces_a_custom_role_permission_set(): void
    {
        $role = $this->customRole();

        $response = $this->actingInTenant($this->merchant)
            ->putJson("/api/v1/tenant/roles/{$role->getKey()}/permissions", [
                'permissions' => ['products.view', 'orders.view'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEqualsCanonicalizing(
            ['products.view', 'orders.view'],
            $response->json('data.permission_slugs'),
        );
    }

    public function test_syncing_permissions_requires_the_permission_list(): void
    {
        $role = $this->customRole();

        $this->actingInTenant($this->merchant)
            ->putJson("/api/v1/tenant/roles/{$role->getKey()}/permissions", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');
    }

    public function test_syncing_permissions_rejects_an_unknown_permission(): void
    {
        $role = $this->customRole();

        $this->actingInTenant($this->merchant)
            ->putJson("/api/v1/tenant/roles/{$role->getKey()}/permissions", [
                'permissions' => ['made.up.permission'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions.0' => 'Unknown permission.']);
    }

    public function test_a_system_roles_permissions_cannot_be_changed(): void
    {
        $merchantRole = Role::query()->where('slug', 'merchant')->firstOrFail();

        $this->actingInTenant($this->merchant)
            ->putJson("/api/v1/tenant/roles/{$merchantRole->getKey()}/permissions", [
                'permissions' => ['dashboard.view'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Permissions of a system role cannot be changed.');
    }

    public function test_a_tenant_administrator_cannot_mint_a_platform_role(): void
    {
        $this->actingInTenant($this->merchant)
            ->postJson('/api/v1/tenant/roles', [
                'name' => 'Platform Helpdesk',
                'scope' => 'platform',
                'permissions' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope' => 'Only platform administrators may create platform roles.']);
    }

    public function test_a_super_admin_can_mint_a_platform_role(): void
    {
        $admin = $this->platformUser('super-admin');
        $admin->tenants()->attach($this->tenant->getKey());

        $this->actingInTenant($admin)
            ->postJson('/api/v1/tenant/roles', [
                'name' => 'Platform Helpdesk',
                'scope' => 'platform',
                'permissions' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'platform');
    }

    public function test_a_platform_role_cannot_be_assigned_from_a_tenant(): void
    {
        $platformRole = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $target = $this->memberOf($this->tenant, 'staff');

        $this->actingInTenant($this->merchant)
            ->postJson("/api/v1/tenant/roles/{$platformRole->getKey()}/assign", [
                'user_id' => $target->getKey(),
            ])
            ->assertForbidden()
            ->assertJsonPath('message', self::PLATFORM_GUARD);

        $this->assertDatabaseMissing('user_roles', [
            'role_id' => $platformRole->getKey(),
            'user_id' => $target->getKey(),
        ]);
    }

    public function test_every_action_on_a_platform_role_is_refused_from_a_tenant(): void
    {
        $platformRole = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $target = $this->memberOf($this->tenant, 'staff');
        $url = "/api/v1/tenant/roles/{$platformRole->getKey()}";
        $request = fn () => $this->actingInTenant($this->merchant);

        $request()->getJson($url)->assertForbidden()->assertJsonPath('message', self::PLATFORM_GUARD);
        $request()->patchJson($url, ['name' => 'Renamed'])->assertForbidden()->assertJsonPath('message', self::PLATFORM_GUARD);
        $request()->deleteJson($url)->assertForbidden()->assertJsonPath('message', self::PLATFORM_GUARD);
        $request()->putJson("{$url}/permissions", ['permissions' => []])->assertForbidden()->assertJsonPath('message', self::PLATFORM_GUARD);
        $request()->postJson("{$url}/revoke", ['user_id' => $target->getKey()])->assertForbidden()->assertJsonPath('message', self::PLATFORM_GUARD);
    }

    public function test_a_platform_operator_can_assign_a_platform_role_from_a_tenant(): void
    {
        $admin = $this->platformUser('dukavo-admin');
        $admin->tenants()->attach($this->tenant->getKey());
        $target = $this->memberOf($this->tenant, 'staff');
        $supportRole = Role::query()->where('slug', 'dukavo-admin')->firstOrFail();

        $this->actingInTenant($admin)
            ->postJson("/api/v1/tenant/roles/{$supportRole->getKey()}/assign", [
                'user_id' => $target->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', null);

        $this->assertDatabaseHas('user_roles', [
            'role_id' => $supportRole->getKey(),
            'user_id' => $target->getKey(),
            'tenant_id' => null,
        ]);
    }

    public function test_a_custom_role_can_be_assigned_and_revoked(): void
    {
        $role = $this->customRole();
        $target = $this->memberOf($this->tenant, 'staff');
        $url = "/api/v1/tenant/roles/{$role->getKey()}";

        $this->actingInTenant($this->merchant)
            ->postJson("{$url}/assign", ['user_id' => $target->getKey()])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->tenant->getKey());

        $this->assertDatabaseHas('user_roles', [
            'role_id' => $role->getKey(),
            'user_id' => $target->getKey(),
            'tenant_id' => $this->tenant->getKey(),
        ]);

        $this->actingInTenant($this->merchant)
            ->postJson("{$url}/revoke", ['user_id' => $target->getKey()])
            ->assertOk()
            ->assertJsonPath('data.role', $role->slug);

        $this->assertDatabaseMissing('user_roles', [
            'role_id' => $role->getKey(),
            'user_id' => $target->getKey(),
            'tenant_id' => $this->tenant->getKey(),
        ]);
    }

    public function test_a_role_cannot_be_assigned_to_a_non_member(): void
    {
        $role = $this->customRole();
        $stranger = User::factory()->create();

        $this->actingInTenant($this->merchant)
            ->postJson("/api/v1/tenant/roles/{$role->getKey()}/assign", [
                'user_id' => $stranger->getKey(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The user is not a member of that tenant.');
    }

    public function test_assigning_a_role_requires_a_user_id(): void
    {
        $role = $this->customRole();

        $this->actingInTenant($this->merchant)
            ->postJson("/api/v1/tenant/roles/{$role->getKey()}/assign", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_the_role_endpoints_require_permission(): void
    {
        $role = $this->customRole();
        $staff = $this->memberOf($this->tenant, 'staff');
        $url = "/api/v1/tenant/roles/{$role->getKey()}";

        $this->actingInTenant($staff)
            ->putJson("{$url}/permissions", ['permissions' => ['dashboard.view']])
            ->assertForbidden();

        $this->actingInTenant($staff)
            ->postJson("{$url}/assign", ['user_id' => $staff->getKey()])
            ->assertForbidden();
    }

    public function test_the_role_endpoints_require_authentication(): void
    {
        $this->putJson('/api/v1/tenant/roles/1/permissions', ['permissions' => []])
            ->assertStatus(401);
    }

    private function customRole(string $name = 'Warehouse'): Role
    {
        $id = $this->actingInTenant($this->merchant)
            ->postJson('/api/v1/tenant/roles', [
                'name' => $name,
                'scope' => 'tenant',
                'permissions' => ['dashboard.view'],
            ])
            ->assertCreated()
            ->json('data.id');

        return Role::query()->findOrFail($id);
    }

    private function actingInTenant(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug);
    }
}
