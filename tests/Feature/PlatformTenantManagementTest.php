<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Platform tenant administration: suspend and activate.
 *
 * Suspending a tenant also has to stamp (and activating clear) the derived
 * `suspended_at` column. That column is deliberately not mass assignable, so
 * these tests guard the service against reaching for it through `fill()`.
 */
class PlatformTenantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_platform_operator_can_suspend_and_reactivate_a_tenant(): void
    {
        $tenant = $this->tenantWithStore();
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/tenants/{$tenant->getKey()}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $tenant->refresh();
        $this->assertSame(TenantStatus::Suspended, $tenant->status);
        $this->assertNotNull($tenant->suspended_at);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/tenants/{$tenant->getKey()}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $tenant->refresh();
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertNull($tenant->suspended_at);
    }

    public function test_updating_status_to_suspended_stamps_the_suspension(): void
    {
        $tenant = $this->tenantWithStore();
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/platform/tenants/{$tenant->getKey()}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $tenant->refresh();
        $this->assertSame(TenantStatus::Suspended, $tenant->status);
        $this->assertNotNull($tenant->suspended_at);
    }

    public function test_resuspending_keeps_the_original_suspension_timestamp(): void
    {
        $suspendedAt = Carbon::parse('2026-06-15 12:00:00');
        $this->travelTo($suspendedAt);

        $tenant = Tenant::factory()->suspended()->create();

        $this->travelBack();

        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/tenants/{$tenant->getKey()}/suspend")
            ->assertOk();

        $this->assertSame(
            $suspendedAt->toDateTimeString(),
            $tenant->refresh()->suspended_at?->toDateTimeString(),
        );
    }

    public function test_a_merchant_cannot_suspend_a_tenant(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->memberOf($tenant, 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->postJson("/api/v1/platform/tenants/{$tenant->getKey()}/suspend")
            ->assertForbidden();
    }
}
