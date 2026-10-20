<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform staff endpoints (Dukavo's own accounts).
 *
 * Only users holding a platform scoped role are Dukavo staff; a merchant is a
 * tenant member and is managed through the tenant team endpoints. The endpoints
 * must work without any tenant context and must refuse tenant scoped roles.
 */
class PlatformUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function platformRole(string $slug = 'dukavo-admin'): Role
    {
        return Role::query()->where('slug', $slug)->where('scope', RoleScope::Platform)->firstOrFail();
    }

    private function tenantRole(string $slug = 'merchant'): Role
    {
        return Role::query()->where('slug', $slug)->where('scope', RoleScope::Tenant)->firstOrFail();
    }

    public function test_a_tenant_merchant_cannot_reach_the_platform_user_endpoints(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/users')
            ->assertForbidden();

        $this->actingAs($merchant, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Rogue',
                'email' => 'rogue@dukavo.test',
                'password' => 'secret-secret-123',
                'role_id' => $this->platformRole()->getKey(),
            ])
            ->assertForbidden();
    }

    public function test_the_platform_user_index_lists_only_platform_staff(): void
    {
        $this->memberOf($this->tenantWithStore(), 'merchant');

        $superAdmin = $this->platformUser('super-admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $superAdmin->getKey());
    }

    public function test_a_platform_operator_can_invite_platform_staff(): void
    {
        $superAdmin = $this->platformUser('super-admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Omar Khoury',
                'email' => 'omar@dukavo.test',
                'phone' => '+96170000000',
                'password' => 'very-strong-password',
                'password_confirmation' => 'very-strong-password',
                'locale' => 'ar',
                'role_id' => $this->platformRole()->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'omar@dukavo.test')
            ->assertJsonPath('data.roles.0.slug', 'dukavo-admin');

        $staff = User::query()->where('email', 'omar@dukavo.test')->firstOrFail();

        $this->assertNull($staff->tenants()->first()?->getKey(), 'A platform account must not join a tenant.');
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $staff->getKey(),
            'role_id' => $this->platformRole()->getKey(),
            'tenant_id' => null,
        ]);
    }

    public function test_a_platform_password_must_be_confirmed(): void
    {
        $superAdmin = $this->platformUser('super-admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Omar Khoury',
                'email' => 'omar-mismatch@dukavo.test',
                'password' => 'very-strong-password',
                'password_confirmation' => 'something-else-entirely',
                'role_id' => $this->platformRole()->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'omar-mismatch@dukavo.test']);
    }

    public function test_a_tenant_role_cannot_be_granted_to_platform_staff(): void
    {
        $superAdmin = $this->platformUser('super-admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Bound Guess',
                'email' => 'bound@dukavo.test',
                'password' => 'very-strong-password',
                'role_id' => $this->tenantRole()->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_assigning_a_tenant_role_to_platform_staff_is_refused(): void
    {
        $superAdmin = $this->platformUser('super-admin');
        $staff = User::query()->create([
            'name' => 'Platform Aide',
            'email' => 'aide@dukavo.test',
            'password' => 'very-strong-password',
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/users/{$staff->getKey()}/roles/{$this->tenantRole()->getKey()}")
            ->assertStatus(409);
    }

    public function test_platform_roles_can_be_reassigned(): void
    {
        $superAdmin = $this->platformUser('super-admin');
        $staff = User::query()->create([
            'name' => 'Platform Aide',
            'email' => 'aide@dukavo.test',
            'password' => 'very-strong-password',
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/users/{$staff->getKey()}/roles/{$this->platformRole()->getKey()}")
            ->assertCreated();

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/platform/users/{$staff->getKey()}/roles/{$this->platformRole()->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $staff->getKey(),
            'role_id' => $this->platformRole()->getKey(),
        ]);
    }

    public function test_a_platform_merchant_account_cannot_be_read_as_staff(): void
    {
        $superAdmin = $this->platformUser('super-admin');
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/v1/platform/users/{$merchant->getKey()}")
            ->assertNotFound();
    }

    public function test_the_last_active_super_admin_cannot_be_suspended(): void
    {
        $superAdmin = $this->platformUser('super-admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/platform/users/{$superAdmin->getKey()}", [
                'status' => UserStatus::Suspended->value,
            ])
            ->assertStatus(409);
    }

    public function test_a_super_admin_with_a_replacement_can_be_suspended(): void
    {
        $superAdmin = $this->platformUser('super-admin');
        $replacement = User::query()->create([
            'name' => 'Handover',
            'email' => 'handover@dukavo.test',
            'password' => 'very-strong-password',
            'status' => UserStatus::Active,
        ]);
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/users/{$replacement->getKey()}/roles/{$this->platformRole('super-admin')->getKey()}")
            ->assertCreated();

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/platform/users/{$superAdmin->getKey()}", [
                'status' => UserStatus::Suspended->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::Suspended->value);
    }

    public function test_the_last_super_admin_cannot_have_the_role_stripped(): void
    {
        $superAdmin = $this->platformUser('super-admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/platform/users/{$superAdmin->getKey()}/roles/{$this->platformRole('super-admin')->getKey()}")
            ->assertStatus(409);
    }
}
