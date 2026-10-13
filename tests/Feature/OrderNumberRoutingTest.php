<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders are read by their human number and mutated by their primary key.
 *
 * The two identifiers are deliberately different: a merchant shares ORD-000123
 * with a customer, while stock and history side effects must key on a value the
 * client cannot swap for a sibling order.
 */
class OrderNumberRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_an_order_is_shown_by_its_order_number(): void
    {
        $tenant = $this->tenantWithStore();
        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $order = $this->order($tenant, $store, 'ORD-000123');

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/orders/ORD-000123')
            ->assertOk()
            ->assertJsonPath('data.order_number', 'ORD-000123')
            ->assertJsonPath('data.id', $order->getKey());
    }

    public function test_a_foreign_tenants_order_number_is_not_found(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $theirStore = Store::withoutGlobalScopes()->where('tenant_id', $theirs->getKey())->firstOrFail();

        $this->order($theirs, $theirStore, 'ORD-000999');

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/orders/ORD-000999')
            ->assertNotFound();
    }

    public function test_an_order_is_still_updated_by_its_primary_key(): void
    {
        $tenant = $this->tenantWithStore();
        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $order = $this->order($tenant, $store, 'ORD-000555');

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/orders/'.$order->getKey(), ['customer_name' => 'Renamed Buyer'])
            ->assertOk()
            ->assertJsonPath('data.customer_name', 'Renamed Buyer');
    }

    private function order(Tenant $tenant, Store $store, string $number): Order
    {
        return $this->withinTenant($tenant, fn () => Order::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'store_id' => $store->getKey(),
            'order_number' => $number,
        ]));
    }
}
