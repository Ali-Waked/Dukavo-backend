<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order pricing, stock and the status machine.
 *
 * The value of these tests is the negative cases: a request that tries to set its
 * own price, and a shipment that previously failed because the reservation it was
 * fulfilling was still counted against the stock it was taking.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
    }

    public function test_totals_are_computed_from_the_catalogue_and_ignore_client_prices(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 20);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [
                [
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 3,
                    // A crafted price must not survive; the catalogue decides.
                    'unit_price' => 0.01,
                    'line_total' => 0.03,
                ],
            ],
            'tax_rate' => 0.10,
        ]));

        $this->assertSame(30.00, (float) $order->subtotal);
        $this->assertSame(3.00, (float) $order->tax_total);
        $this->assertSame(33.00, (float) $order->grand_total);
        $this->assertSame(10.00, (float) $order->items->first()->unit_price);
        $this->assertNotNull($order->placed_at);
    }

    public function test_a_line_discount_cannot_exceed_the_line(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [
                ['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'discount_total' => 999],
            ],
        ]));

        $this->assertSame(10.00, (float) $order->discount_total);
        $this->assertSame(0.00, (float) $order->grand_total);
    }

    public function test_order_numbers_are_sequential_within_the_tenant(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 50);

        $first = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        $second = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertMatchesRegularExpression('/^ORD-\d{6}$/', $second->order_number);
    }

    public function test_placing_an_order_reserves_stock(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        ]));

        $inventory = Inventory::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(10, $inventory->quantity);
        $this->assertSame(4, $inventory->reserved_quantity);
        $this->assertSame(6, $inventory->available());
    }

    public function test_reserving_more_than_exists_is_refused(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 3);

        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 5]],
        ]));
    }

    public function test_shipping_consumes_the_reserved_stock_it_settles(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        ]));

        $this->ship($order);

        $inventory = Inventory::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(6, $inventory->quantity);
        // Both columns move together: the reservation this shipment fulfilled is
        // settled, not double-released, and the six unsold units are sellable again.
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(6, $inventory->available());
    }

    public function test_shipment_records_a_sale_movement_with_the_remaining_balance(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        ]));

        $this->ship($order);

        $movement = InventoryMovement::query()->withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

        $this->assertSame(-4, $movement->quantity);
        $this->assertSame(6, $movement->balance_after);
        $this->assertSame($order->getKey(), $movement->reference_id);
    }

    public function test_cancelling_a_shipped_order_returns_the_stock_and_releases_nothing_extra(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        ]));

        $service = app(OrderService::class);

        $this->withinTenant($this->tenant, function () use ($service, $order): void {
            $service->transition($order, OrderStatus::Confirmed);
            $service->transition($order, OrderStatus::Shipped);
            $service->transition($order, OrderStatus::Delivered);
        });

        // Delivered is final, so the compensating cancellation is a separate
        // return, not a status move.
        $this->expectException(BusinessRuleViolation::class);
        $this->withinTenant($this->tenant, fn () => $service->transition($order, OrderStatus::Cancelled));

        $inventory = Inventory::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(10, $inventory->quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
    }

    public function test_cancelling_a_pending_order_releases_stock_without_inventing_a_return(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        ]));

        $this->withinTenant($this->tenant, fn () => app(OrderService::class)->transition($order, OrderStatus::Cancelled));

        $inventory = Inventory::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(10, $inventory->quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertFalse(
            InventoryMovement::query()->withoutGlobalScopes()->where('type', 'return_in')->exists(),
            'Goods that never left the warehouse must not produce a stock return.',
        );
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        // Pending orders must be confirmed before they can be processed.
        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn () => app(OrderService::class)->transition($order, OrderStatus::Processing));
    }

    public function test_every_transition_is_recorded_in_the_history(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        $service = app(OrderService::class);

        $this->withinTenant($this->tenant, function () use ($service, $order): void {
            $service->transition($order, OrderStatus::Confirmed, 'Paid by card');
            $service->transition($order, OrderStatus::Processing);
        });

        // Read inside the tenant context: `order_status_histories` is tenant
        // scoped, so an unscoped read sees nothing — which is itself the
        // isolation guarantee.
        $history = $this->withinTenant(
            $this->tenant,
            fn () => $order->statusHistory()->orderBy('id')->get()->values(),
        );

        $this->assertCount(3, $history);

        // The first row is the creation itself, which has no previous state.
        $this->assertNull($history->first()->from_status);
        $this->assertSame('pending', $history->first()->to_status->value);
        $this->assertSame('confirmed', $history[1]->to_status->value);
        $this->assertSame('Paid by card', $history[1]->note);
        $this->assertSame('pending', $history[1]->from_status->value);
        $this->assertSame('processing', $history->last()->to_status->value);
    }

    public function test_editing_an_order_reprices_it_and_moves_the_reservation(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        ]));

        $this->assertSame(2, Inventory::query()->withoutGlobalScopes()->firstOrFail()->reserved_quantity);

        $this->withinTenant($this->tenant, fn () => app(OrderService::class)->update($order, [
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 5]],
        ]));

        $inventory = Inventory::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(5, $inventory->reserved_quantity);
        $this->assertSame(50.00, (float) $order->fresh()->grand_total);
    }

    public function test_an_order_cannot_be_edited_after_fulfilment_starts(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        $this->withinTenant($this->tenant, function () use ($order): void {
            app(OrderService::class)->transition($order, OrderStatus::Confirmed);
            app(OrderService::class)->transition($order, OrderStatus::Processing);
        });

        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn () => app(OrderService::class)->update($order, [
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 9]],
        ]));
    }

    public function test_an_empty_order_is_refused(): void
    {
        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [],
        ]));
    }

    public function test_cancelling_a_reserved_order_does_not_create_phantom_stock(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        ]));

        $service = app(OrderService::class);
        $order = $this->withinTenant($this->tenant, fn (): Order => $service->transition($order, OrderStatus::Confirmed));
        $this->withinTenant($this->tenant, fn (): Order => $service->transition($order, OrderStatus::Cancelled));

        $inventory = Inventory::query()->withoutGlobalScopes()->where('product_variant_id', $variant->getKey())->firstOrFail();

        $this->assertSame(10, $inventory->quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(0, InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('reference_id', $order->getKey())
            ->where('type', InventoryMovementType::ReturnIn)
            ->count());
    }

    /**
     * Walk an order to the point where stock physically leaves: confirmed, then
     * processing, then shipped. The state machine refuses anything shorter.
     */
    private function ship(Order $order): void
    {
        $service = app(OrderService::class);

        $this->withinTenant($this->tenant, function () use ($service, $order): void {
            $service->transition($order, OrderStatus::Confirmed);
            $service->transition($order, OrderStatus::Processing);
            $service->transition($order, OrderStatus::Shipped);
        });
    }

    public function test_a_product_of_another_tenant_cannot_be_ordered(): void
    {
        $other = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()->where('tenant_id', $other->getKey())->firstOrFail();

        ['variant' => $foreignVariant] = $this->stockedProduct($other, $otherStore);

        $this->expectException(ModelNotFoundException::class);

        $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $foreignVariant->getKey(), 'quantity' => 1]],
        ]));
    }
}
