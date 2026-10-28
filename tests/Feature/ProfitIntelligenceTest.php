<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleScope;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Profit reporting has to be honest before it is useful.
 *
 * These tests pin down the recognition rules, the refusal to value uncosted
 * history, explicit limitation reporting, currency isolation, tenant timezone
 * boundaries, and the separate export permission.
 */
class ProfitIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->user = $this->memberOf($this->tenant);
    }

    public function test_profit_uses_net_sales_and_snapshotted_cost_while_excluding_cancelled_and_refunded_orders(): void
    {
        $recognised = $this->order([
            'subtotal' => 100,
            'discount_total' => 10,
            'tax_total' => 0,
            'shipping_total' => 5,
            'grand_total' => 95,
        ]);
        $this->item($recognised, [
            'name' => 'Cotton shirt',
            'unit_price' => 50,
            'unit_cost' => 30,
            'cost_currency' => 'USD',
            'quantity' => 2,
            'discount_total' => 10,
            'line_total' => 90,
        ]);

        $cancelled = $this->order([
            'subtotal' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
        $this->item($cancelled, ['unit_price' => 40, 'unit_cost' => 10, 'cost_currency' => 'USD']);

        $refunded = $this->order([
            'subtotal' => 20,
            'grand_total' => 20,
            'payment_status' => PaymentStatus::Refunded,
        ]);
        $this->item($refunded, ['unit_price' => 20, 'unit_cost' => 10, 'cost_currency' => 'USD']);

        $response = $this->profit()->assertOk();

        $response
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.summary.total_orders', 3)
            ->assertJsonPath('data.summary.recognized_orders', 1)
            ->assertJsonPath('data.summary.gross_sales', 100)
            ->assertJsonPath('data.summary.discounts', 10)
            ->assertJsonPath('data.summary.net_sales', 90)
            ->assertJsonPath('data.summary.cogs', 60)
            ->assertJsonPath('data.summary.gross_profit', 30)
            ->assertJsonPath('data.summary.gross_margin_percent', 33.33)
            ->assertJsonPath('data.summary.cost_complete', true)
            ->assertJsonPath('data.summary.cancelled_orders', 1)
            ->assertJsonPath('data.summary.refunded_orders', 1)
            ->assertJsonPath('data.refunds.refunded_order_value', 20)
            ->assertJsonPath('data.refunds.refund_amount_recorded', false);

        $response->assertJsonPath('data.products.0.name', 'Cotton shirt')
            ->assertJsonPath('data.products.0.gross_profit', 30)
            ->assertJsonPath('data.stores.0.store_id', $this->store->getKey());
    }

    public function test_gross_profit_is_withheld_when_any_recognised_line_lacks_a_cost_snapshot(): void
    {
        $order = $this->order([
            'subtotal' => 30,
            'grand_total' => 30,
        ]);
        $this->item($order, [
            'name' => 'Costed line',
            'unit_price' => 10,
            'unit_cost' => 4,
            'cost_currency' => 'USD',
        ]);
        $this->item($order, [
            'name' => 'Uncosted line',
            'unit_price' => 20,
            'unit_cost' => null,
            'cost_currency' => null,
        ]);

        $response = $this->profit()->assertOk();

        $response
            ->assertJsonPath('data.summary.cogs', 4)
            ->assertJsonPath('data.summary.uncosted_units', 1)
            ->assertJsonPath('data.summary.uncosted_net_sales', 20)
            ->assertJsonPath('data.summary.cost_complete', false)
            ->assertJsonPath('data.summary.cost_coverage_percent', 50)
            ->assertJsonPath('data.summary.gross_profit', null)
            ->assertJsonPath('data.summary.gross_margin_percent', null)
            ->assertJsonPath('data.series.0.cost_complete', false)
            ->assertJsonPath('data.series.0.gross_profit', null);

        $this->assertContains(
            'historical_cost_incomplete',
            array_column($response->json('data.limitations'), 'code'),
        );
    }

    public function test_partial_payments_are_visible_but_cash_and_outstanding_totals_are_not_invented(): void
    {
        $this->order([
            'subtotal' => 100,
            'grand_total' => 100,
            'payment_status' => PaymentStatus::PartiallyPaid,
        ]);
        $this->order([
            'subtotal' => 30,
            'grand_total' => 30,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
        $this->order([
            'subtotal' => 50,
            'grand_total' => 50,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $response = $this->profit()->assertOk();

        $response
            ->assertJsonPath('data.summary.net_sales', 180)
            ->assertJsonPath('data.payments.marked_paid_value', 50)
            ->assertJsonPath('data.payments.partially_paid_orders', 1)
            ->assertJsonPath('data.payments.partially_paid_value', 100)
            ->assertJsonPath('data.payments.unpaid_value', 30)
            ->assertJsonPath('data.payments.collected_payments', null)
            ->assertJsonPath('data.payments.outstanding_amount', null);

        $this->assertContains(
            'payment_amounts_not_recorded',
            array_column($response->json('data.limitations'), 'code'),
        );
    }

    public function test_without_partial_payments_the_unpaid_value_is_reported_as_outstanding(): void
    {
        $this->order([
            'subtotal' => 30,
            'grand_total' => 30,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        $this->profit()
            ->assertOk()
            ->assertJsonPath('data.payments.outstanding_amount', 30);
    }

    public function test_report_days_follow_the_tenant_timezone_not_utc(): void
    {
        $this->tenant->forceFill(['timezone' => 'Asia/Riyadh'])->save();

        $this->order([
            'subtotal' => 40,
            'grand_total' => 40,
            'placed_at' => Carbon::parse('2026-10-25 22:30:00', 'UTC'),
        ]);

        $this->profit(['from' => '2026-10-26', 'until' => '2026-10-26'])
            ->assertOk()
            ->assertJsonPath('data.range.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.summary.recognized_orders', 1)
            ->assertJsonPath('data.series.0.date', '2026-10-26');

        $this->profit(['from' => '2026-10-25', 'until' => '2026-10-25'])
            ->assertOk()
            ->assertJsonPath('data.summary.recognized_orders', 0);
    }

    public function test_currencies_are_filtered_and_never_added_together(): void
    {
        $this->order(['subtotal' => 100, 'grand_total' => 100, 'currency' => 'USD']);
        $this->order(['subtotal' => 60, 'grand_total' => 60, 'currency' => 'EUR']);

        $this->profit()
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.summary.net_sales', 100);

        $this->profit(['currency' => 'eur'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.summary.net_sales', 60);
    }

    public function test_foreign_stores_are_rejected_and_staff_cannot_read_financials(): void
    {
        $other = $this->tenantWithStore();
        $foreign = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $other->getKey())
            ->firstOrFail();

        $this->profit(['store_id' => $foreign->getKey()])->assertUnprocessable();

        $staff = $this->memberOf($this->tenant, 'staff');

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/profit')
            ->assertForbidden();

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/profit/export')
            ->assertForbidden();
    }

    public function test_viewing_and_exporting_are_separate_permissions_and_the_csv_is_formula_safe(): void
    {
        $this->store->forceFill(['name' => '=HYPERLINK("https://evil.example")'])->save();

        $this->order([
            'subtotal' => 10,
            'grand_total' => 10,
        ])->forceFill(['order_number' => '=1+1'])->save();

        $role = Role::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Profit viewer',
            'slug' => 'profit-viewer',
            'scope' => RoleScope::Tenant,
            'description' => 'Can read but not export',
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::query()->where('slug', 'profit.view')->firstOrFail()->getKey(),
        ]);

        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant->getKey(), ['is_owner' => false]);
        $viewer->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $this->tenant->getKey(),
        ]);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/profit')
            ->assertOk();

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/profit/export')
            ->assertForbidden();

        $export = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/v1/tenant/profit/export')
            ->assertOk();

        $csv = $export->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_cost_snapshots_survive_a_later_catalogue_cost_change(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $this->withinTenant($this->tenant, function () use ($product, $variant): void {
            $product->forceFill(['cost' => 3, 'currency' => 'USD'])->save();
            $variant->forceFill(['cost' => 4])->save();
        });

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [
                ['product_variant_id' => $variant->getKey(), 'quantity' => 2],
            ],
        ], false));

        $item = $this->withinTenant(
            $this->tenant,
            fn (): OrderItem => OrderItem::query()->where('order_id', $order->getKey())->firstOrFail(),
        );

        $this->assertSame(4.0, (float) $item->unit_cost);
        $this->assertSame('USD', $item->cost_currency);
        $this->assertNotNull($item->cost_captured_at);

        $this->withinTenant($this->tenant, fn () => $variant->forceFill(['cost' => 9])->save());

        $this->profit()
            ->assertOk()
            ->assertJsonPath('data.summary.net_sales', 20)
            ->assertJsonPath('data.summary.cogs', 8)
            ->assertJsonPath('data.summary.gross_profit', 12);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'currency' => 'USD',
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Paid,
            'placed_at' => now(),
            'subtotal' => 20,
            'discount_total' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'grand_total' => 20,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(Order $order, array $attributes = []): OrderItem
    {
        return $this->withinTenant($this->tenant, fn (): OrderItem => OrderItem::query()->create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'order_id' => $order->getKey(),
            'product_id' => null,
            'product_variant_id' => null,
            'name' => 'Item',
            'sku' => null,
            'unit_price' => 20,
            'unit_cost' => null,
            'cost_currency' => null,
            'cost_captured_at' => null,
            'quantity' => 1,
            'discount_total' => 0,
            'tax_total' => 0,
            'line_total' => 20,
        ], $attributes)));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function profit(array $query = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/profit?'.http_build_query($query));
    }
}
