<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleScope;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform roles & permissions endpoints.
 *
 * A platform operator belongs to no tenant, so the route must work without any
 * tenant context. The guard that matters is RoleController's: only platform
 * operators may touch platform scoped roles, and system roles stay locked.
 */
class PlatformRoleEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_tenant_merchant_cannot_reach_the_platform_role_endpoints(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/roles')
            ->assertForbidden();

        $this->actingAs($merchant, 'sanctum')
            ->postJson('/api/v1/platform/roles', ['name' => 'Rogue', 'scope' => RoleScope::Platform->value])
            ->assertForbidden();
    }

    public function test_a_platform_operator_lists_the_whole_catalogue_without_a_tenant_header(): void
    {
        $this->tenantWithStore();

        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/roles')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['slug' => 'super-admin'])
            ->assertJsonFragment(['slug' => 'merchant']);
    }

    public function test_a_platform_operator_can_mint_a_platform_role_and_it_is_audited(): void
    {
        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/platform/roles', [
                'name' => 'Auditor',
                'scope' => RoleScope::Platform->value,
                'permissions' => ['audit.view'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', RoleScope::Platform->value)
            ->assertJsonPath('data.is_system', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'tenant_id' => null,
            'auditable_type' => 'role',
        ]);
    }

    public function test_system_role_permissions_stay_locked(): void
    {
        $operator = $this->platformUser('super-admin');
        $systemRole = Role::query()->where('slug', 'dukavo-admin')->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->putJson("/api/v1/platform/roles/{$systemRole->getKey()}/permissions", [
                'permissions' => ['dashboard.view'],
            ])
            ->assertStatus(409);
    }

    public function test_a_custom_platform_role_can_be_renamed_and_deleted(): void
    {
        $operator = $this->platformUser('super-admin');

        $role = Role::query()->create([
            'name' => 'Temporary Role',
            'slug' => 'temporary-role',
            'scope' => RoleScope::Platform,
            'description' => null,
            'is_system' => false,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/v1/platform/roles/{$role->getKey()}", [
                'name' => 'Temporary Role Renamed',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Temporary Role Renamed');

        $this->actingAs($operator, 'sanctum')
            ->deleteJson("/api/v1/platform/roles/{$role->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('roles', ['id' => $role->getKey()]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted',
            'tenant_id' => null,
            'auditable_type' => 'role',
            'auditable_id' => $role->getKey(),
        ]);
    }

    public function test_role_changes_record_a_tenant_context_when_they_come_from_a_workspace(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->memberOf($tenant, 'merchant');

        $custom = Role::query()->create([
            'name' => 'Warehouse',
            'slug' => 'warehouse',
            'scope' => RoleScope::Tenant,
            'description' => null,
            'is_system' => false,
        ]);

        $this->actingAs($merchant, 'sanctum')
            ->patchJson('/api/v1/tenant/roles/'.$custom->getKey(), ['name' => 'Warehouse Lead'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated',
            'tenant_id' => $tenant->getKey(),
            'auditable_type' => 'role',
            'auditable_id' => $custom->getKey(),
        ]);
    }

    public function test_tenant_role_creation_still_carries_the_tenant_into_the_audit_trail(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->memberOf($tenant, 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->postJson('/api/v1/tenant/roles', [
                'name' => 'Floor Staff',
                'scope' => RoleScope::Tenant->value,
            ])
            ->assertCreated();

        $role = Role::query()->where('slug', 'floor-staff')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'tenant_id' => $tenant->getKey(),
            'auditable_type' => 'role',
            'auditable_id' => $role->getKey(),
        ]);
    }
}
