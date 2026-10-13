<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tenant team endpoints.
 *
 * The risk here is privilege: a merchant with `users.manage` must be able to run
 * their own team, and must never be able to reach Dukavo staff through the same
 * endpoint — neither by granting a platform role to a member nor by mutating a
 * platform administrator who was attached to the workspace for the switcher.
 */
class TenantUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_a_platform_role_cannot_be_granted_when_adding_a_team_member(): void
    {
        $this->actingInTenant($this->merchant)
            ->postJson('/api/v1/tenant/users', [
                'name' => 'Rogue Admin',
                'email' => 'rogue@urban-basket.test',
                'role_id' => $this->platformRole()->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'rogue@urban-basket.test']);
    }

    public function test_a_platform_role_cannot_be_reassigned_to_a_team_member(): void
    {
        $staff = $this->memberOf($this->tenant, 'staff');
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        $this->actingInTenant($this->merchant)
            ->patchJson("/api/v1/tenant/users/{$staff->getKey()}", [
                'role_id' => $this->platformRole()->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $staff->getKey(),
            'role_id' => $staffRole->getKey(),
            'tenant_id' => $this->tenant->getKey(),
        ]);
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $staff->getKey(),
            'role_id' => $this->platformRole()->getKey(),
        ]);
    }

    public function test_the_team_index_excludes_platform_administrators(): void
    {
        $admin = $this->platformUser('super-admin');
        $admin->tenants()->attach($this->tenant->getKey());

        $this->actingInTenant($this->merchant)
            ->getJson('/api/v1/tenant/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->merchant->getKey());
    }

    public function test_a_platform_administrator_cannot_be_managed_from_a_tenant(): void
    {
        $admin = $this->platformUser('super-admin');
        $admin->tenants()->attach($this->tenant->getKey());

        $url = "/api/v1/tenant/users/{$admin->getKey()}";

        $this->actingInTenant($this->merchant)->getJson($url)->assertForbidden();

        $this->actingInTenant($this->merchant)
            ->patchJson($url, ['status' => UserStatus::Suspended->value])
            ->assertForbidden();

        $this->actingInTenant($this->merchant)->deleteJson($url)->assertForbidden();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    public function test_a_merchant_can_still_manage_their_own_team(): void
    {
        $created = $this->actingInTenant($this->merchant)
            ->postJson('/api/v1/tenant/users', [
                'name' => 'Nadia Haddad',
                'email' => 'nadia@urban-basket.test',
                'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->getKey(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingInTenant($this->merchant)
            ->patchJson("/api/v1/tenant/users/{$created}", ['name' => 'Nadia H.'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nadia H.');

        $this->actingInTenant($this->merchant)
            ->deleteJson("/api/v1/tenant/users/{$created}")
            ->assertNoContent();
    }

    public function test_a_platform_operator_can_still_manage_a_team_member(): void
    {
        $support = $this->platformUser('dukavo-admin');
        $support->tenants()->attach($this->tenant->getKey());
        $staff = $this->memberOf($this->tenant, 'staff');

        $this->actingInTenant($support)
            ->patchJson("/api/v1/tenant/users/{$staff->getKey()}", ['name' => 'Renamed By Support'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed By Support');
    }

    private function actingInTenant(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function platformRole(string $slug = 'super-admin'): Role
    {
        return Role::query()->where('slug', $slug)->firstOrFail();
    }
}
