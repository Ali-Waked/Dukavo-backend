<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform audit endpoint.
 *
 * `audit_logs` is intentionally not tenant-scoped: platform events carry a NULL
 * tenant, so only the platform trail can serve them. A platform operator reads
 * both the platform events and every tenant's events; a tenant user never reads
 * outside its own tenant.
 */
class PlatformAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_tenant_merchant_cannot_reach_the_platform_audit_endpoint(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/audit-logs')
            ->assertForbidden();
    }

    public function test_a_platform_operator_sees_the_platform_trail_when_requested(): void
    {
        $operator = $this->platformUser('super-admin');

        AuditLog::create([
            'action' => AuditAction::Created,
            'auditable_type' => 'role',
            'auditable_id' => 1,
            'tenant_id' => null,
            'user_id' => $operator->getKey(),
            'old_values' => null,
            'new_values' => ['name' => 'Auditor'],
            'created_at' => now(),
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/audit-logs?platform=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'created')
            ->assertJsonPath('data.0.tenant_id', null)
            ->assertJsonPath('data.0.auditable_type', 'role');
    }

    public function test_a_platform_operator_can_follow_any_tenant_s_events(): void
    {
        $operator = $this->platformUser('super-admin');
        $tenantA = $this->tenantWithStore();
        $tenantB = Tenant::factory()->create();

        AuditLog::create([
            'action' => AuditAction::Created,
            'auditable_type' => 'tenant',
            'auditable_id' => $tenantA->getKey(),
            'tenant_id' => $tenantA->getKey(),
            'user_id' => $operator->getKey(),
            'old_values' => null,
            'new_values' => [],
            'created_at' => now(),
        ]);
        AuditLog::create([
            'action' => AuditAction::Created,
            'auditable_type' => 'tenant',
            'auditable_id' => $tenantB->getKey(),
            'tenant_id' => $tenantB->getKey(),
            'user_id' => $operator->getKey(),
            'old_values' => null,
            'new_values' => [],
            'created_at' => now(),
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/audit-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_tenant_user_sees_only_its_own_tenant_s_events(): void
    {
        $tenantA = $this->tenantWithStore();
        $tenantB = Tenant::factory()->create();
        $merchantA = $this->memberOf($tenantA, 'merchant');

        $eventA = AuditLog::create([
            'action' => AuditAction::Created,
            'auditable_type' => 'tenant',
            'auditable_id' => $tenantA->getKey(),
            'tenant_id' => $tenantA->getKey(),
            'user_id' => $merchantA->getKey(),
            'old_values' => null,
            'new_values' => [],
            'created_at' => now(),
        ]);
        $eventB = AuditLog::create([
            'action' => AuditAction::Created,
            'auditable_type' => 'tenant',
            'auditable_id' => $tenantB->getKey(),
            'tenant_id' => $tenantB->getKey(),
            'user_id' => $merchantA->getKey(),
            'old_values' => null,
            'new_values' => [],
            'created_at' => now(),
        ]);

        $this->actingAs($merchantA, 'sanctum')
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/v1/tenant/audit-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventA->getKey());

        $this->actingAs($merchantA, 'sanctum')
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/v1/tenant/audit-logs/'.$eventB->getKey())
            ->assertNotFound();
    }

    public function test_platform_user_changes_land_in_the_platform_trail(): void
    {
        $superAdmin = $this->platformUser('super-admin');
        $role = Role::query()->where('slug', 'dukavo-admin')->firstOrFail();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Tracer',
                'email' => 'tracer@dukavo.test',
                'password' => 'very-strong-password',
                'password_confirmation' => 'very-strong-password',
                'role_id' => $role->getKey(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'tenant_id' => null,
            'auditable_type' => 'user',
        ]);
    }
}
