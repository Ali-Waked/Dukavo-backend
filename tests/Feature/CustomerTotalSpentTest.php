<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer's lifetime value.
 *
 * "Total Spent" is money actually collected, so only fully paid, non-cancelled
 * orders in the tenant's currency count. Every other combination is there to
 * catch a total that is really just "sum every order".
 */
class CustomerTotalSpentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_total_spent_counts_only_paid_non_cancelled_orders_in_the_tenant_currency(): void
    {
        $tenant = $this->tenantWithStore();
        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $user = $this->memberOf($tenant);

        $customer = $this->withinTenant($tenant, fn () => Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Lifetime Buyer',
        ]));

        $this->order($tenant, $store, $customer, 100, PaymentStatus::Paid, OrderStatus::Delivered, 'USD');
        $this->order($tenant, $store, $customer, 50, PaymentStatus::Paid, OrderStatus::Pending, 'USD');
        $this->order($tenant, $store, $customer, 999, PaymentStatus::Unpaid, OrderStatus::Pending, 'USD');
        $this->order($tenant, $store, $customer, 200, PaymentStatus::Refunded, OrderStatus::Delivered, 'USD');
        $this->order($tenant, $store, $customer, 300, PaymentStatus::Paid, OrderStatus::Cancelled, 'USD');
        $this->order($tenant, $store, $customer, 500, PaymentStatus::Paid, OrderStatus::Delivered, 'EUR');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/customers/'.$customer->getKey())
            ->assertOk()
            // orders_count counts every order; total_spent counts only the honest ones.
            ->assertJsonPath('data.orders_count', 6)
            ->assertJsonPath('data.total_spent', 150);
    }

    public function test_the_customer_list_exposes_the_same_total(): void
    {
        $tenant = $this->tenantWithStore();
        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $user = $this->memberOf($tenant);

        $customer = $this->withinTenant($tenant, fn () => Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'List Buyer',
        ]));

        $this->order($tenant, $store, $customer, 75, PaymentStatus::Paid, OrderStatus::Delivered, 'USD');
        $this->order($tenant, $store, $customer, 25, PaymentStatus::Unpaid, OrderStatus::Pending, 'USD');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'List Buyer')
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.total_spent', 75);
    }

    public function test_a_customer_with_no_orders_reports_zero(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $customer = $this->withinTenant($tenant, fn () => Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Quiet Buyer',
        ]));

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/customers/'.$customer->getKey())
            ->assertOk()
            ->assertJsonPath('data.orders_count', 0)
            ->assertJsonPath('data.total_spent', 0);
    }

    private function order(
        Tenant $tenant,
        Store $store,
        Customer $customer,
        float $grandTotal,
        PaymentStatus $paymentStatus,
        OrderStatus $status,
        string $currency,
    ): void {
        $this->withinTenant($tenant, fn () => Order::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'store_id' => $store->getKey(),
            'customer_id' => $customer->getKey(),
            'grand_total' => $grandTotal,
            'payment_status' => $paymentStatus->value,
            'status' => $status->value,
            'currency' => $currency,
        ]));
    }
}
