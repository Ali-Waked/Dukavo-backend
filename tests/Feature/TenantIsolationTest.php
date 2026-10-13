<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tenant boundary.
 *
 * These tests exist because the failure they guard against is invisible: a query
 * that forgets `tenant_id` returns the right shape, the wrong rows, and no error.
 * Every assertion below is about what a request from one tenant can and cannot
 * reach.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_listing_only_returns_rows_of_the_active_tenant(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $user = $this->memberOf($mine);

        $this->withinTenant($mine, fn () => Customer::factory()->create(['tenant_id' => $mine->getKey(), 'name' => 'Mine']));
        $this->withinTenant($theirs, fn () => Customer::factory()->create(['tenant_id' => $theirs->getKey(), 'name' => 'Theirs']));

        $this->actingAs($user)
            ->getJson('/api/v1/tenant/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_a_record_of_another_tenant_is_not_found(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $user = $this->memberOf($mine);

        $customer = $this->withinTenant(
            $theirs,
            fn () => Customer::factory()->create(['tenant_id' => $theirs->getKey()]),
        );

        // 404 rather than 403: a 403 would confirm that the id exists.
        $this->actingAs($user)
            ->getJson('/api/v1/tenant/customers/'.$customer->getKey())
            ->assertNotFound();
    }

    public function test_a_forged_tenant_header_is_refused(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $user = $this->memberOf($mine);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $theirs->slug)
            ->getJson('/api/v1/tenant/customers')
            ->assertStatus(400)
            ->assertJsonPath('message', 'You do not have access to this tenant.');
    }

    public function test_a_suspended_tenant_refuses_even_its_own_member(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $tenant->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/customers')
            ->assertStatus(400)
            ->assertJsonPath('message', 'This tenant is currently suspended.');
    }

    public function test_a_user_of_another_tenant_cannot_be_read_or_edited(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $user = $this->memberOf($mine);
        $stranger = $this->memberOf($theirs);

        $this->actingAs($user)
            ->getJson('/api/v1/tenant/users/'.$stranger->getKey())
            ->assertNotFound();

        $this->actingAs($user)
            ->patchJson('/api/v1/tenant/users/'.$stranger->getKey(), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertNotSame('Hijacked', $stranger->fresh()->name);
    }

    public function test_a_user_with_no_permission_receives_403(): void
    {
        $tenant = $this->tenantWithStore();
        $viewer = $this->memberOf($tenant, 'staff');

        // Staff may read products but must not be able to create one.
        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Sneaky',
                'price' => 1,
            ])
            ->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/tenant/customers')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Authentication is required.');
    }

    public function test_platform_endpoints_are_closed_to_tenant_members(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $this->actingAs($user)
            ->getJson('/api/v1/platform/tenants')
            ->assertForbidden();
    }

    public function test_a_platform_operator_reaches_tenants_without_a_tenant_context(): void
    {
        $tenant = $this->tenantWithStore();
        $operator = $this->platformUser();

        // No X-Tenant header: platform routes must work without one.
        $this->actingAs($operator)
            ->getJson('/api/v1/platform/tenants')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tenant->getKey());
    }

    public function test_a_tenant_route_requires_a_tenant_to_be_resolvable(): void
    {
        $operator = $this->platformUser('dukavo-admin');

        // An operator with no header and no single tenant has nothing to resolve.
        $this->actingAs($operator)
            ->getJson('/api/v1/tenant/customers')
            ->assertStatus(400)
            ->assertJsonPath('message', 'No tenant could be resolved. Send the X-Tenant header with one of your tenants.');
    }

    public function test_tenant_data_cannot_be_created_without_a_context(): void
    {
        $this->expectException(\RuntimeException::class);

        // No TenantContext is set, so the model's boot hook must refuse to guess.
        Store::query()->create(['name' => 'Orphan', 'slug' => 'orphan']);
    }

    public function test_an_order_of_another_tenant_cannot_be_transitioned(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $user = $this->memberOf($mine);

        $order = $this->withinTenant($theirs, fn () => Order::factory()->create([
            'tenant_id' => $theirs->getKey(),
            'store_id' => Store::query()->where('tenant_id', $theirs->getKey())->value('id'),
        ]));

        $this->actingAs($user)
            ->withHeader('X-Tenant', $mine->slug)
            ->postJson('/api/v1/tenant/orders/'.$order->getKey().'/transition', ['status' => 'confirmed'])
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_a_user_with_two_tenants_must_choose_one(): void
    {
        $first = $this->tenantWithStore();
        $second = $this->tenantWithStore();

        // One person who genuinely belongs to both, each with its own role.
        $user = $this->memberOf($first);
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        $user->tenants()->attach($second->getKey(), ['is_owner' => false]);
        $user->roleAssignments()->create([
            'role_id' => $staffRole->getKey(),
            'tenant_id' => $second->getKey(),
        ]);
        $user = $user->fresh();

        // Guessing between two tenants would silently show the wrong merchant's
        // data, so the request must be refused until one is chosen.
        $this->actingAs($user)
            ->getJson('/api/v1/tenant/customers')
            ->assertStatus(400);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $second->slug)
            ->getJson('/api/v1/tenant/customers')
            ->assertOk();
    }

    public function test_membership_is_required_for_the_tenant_switcher(): void
    {
        $tenant = $this->tenantWithStore();
        $other = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.tenants')
            ->assertJsonPath('data.tenants.0.id', $tenant->getKey())
            ->assertJsonMissing(['id' => $other->getKey()]);
    }
}
