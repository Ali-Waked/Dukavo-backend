<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Inventory intelligence is only useful if its arithmetic and its safety both
 * hold. These tests pin down the history-based metrics, the transparent
 * fallback when there is not enough history, low-stock semantics under
 * reservations, idempotent retries, and store transfers.
 */
class InventoryIntelligenceTest extends TestCase
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

    public function test_low_stock_uses_available_stock_after_reservations(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $row = $this->inventoryRow();

        $this->withinTenant($this->tenant, fn () => app(InventoryService::class)->reserve($row, 6));

        $this->assertSame(10, $row->fresh()->quantity);
        $this->assertSame(4, $row->fresh()->available());

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/inventory?low_stock=1&product_variant_id='.$variant->getKey())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 10)
            ->assertJsonPath('data.0.available', 4)
            ->assertJsonPath('data.0.is_low_stock', true);
    }

    public function test_a_retried_receipt_with_the_same_key_posts_only_once(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $payload = [
            'store_id' => $this->store->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 5,
            'idempotency_key' => 'receipt-2026-10-26-001',
        ];

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->user)
                ->withHeader('X-Tenant', $this->tenant->slug)
                ->postJson('/api/v1/tenant/inventory/receive', $payload)
                ->assertCreated();
        }

        $this->assertSame(15, $this->inventoryRow()->quantity);

        $movements = $this->withinTenant(
            $this->tenant,
            fn () => InventoryMovement::query()->where('idempotency_key', $payload['idempotency_key'])->get(),
        );

        $this->assertCount(1, $movements);
        $this->assertSame('purchase', $movements->first()->source);
    }

    public function test_a_transfer_moves_stock_between_stores_and_cannot_be_replayed(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $destination = Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'is_default' => false,
        ]);

        $payload = [
            'from_store_id' => $this->store->getKey(),
            'to_store_id' => $destination->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 4,
            'idempotency_key' => 'transfer-001',
        ];

        $first = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/transfers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.from_store_id', $this->store->getKey())
            ->assertJsonPath('data.to_store_id', $destination->getKey());

        $second = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/transfers', $payload)
            ->assertCreated();

        $second->assertJsonPath('data.id', $first->json('data.id'));

        $rows = $this->withinTenant(
            $this->tenant,
            fn () => Inventory::query()->where('product_variant_id', $variant->getKey())->get()->keyBy('store_id'),
        );

        $this->assertSame(6, $rows[$this->store->getKey()]->quantity);
        $this->assertSame(4, $rows[$destination->getKey()]->quantity);

        $this->assertSame(1, $this->withinTenant($this->tenant, fn () => StockTransfer::query()->count()));
        $this->assertSame(
            2,
            $this->withinTenant(
                $this->tenant,
                fn () => InventoryMovement::query()
                    ->whereIn('type', [InventoryMovementType::TransferOut, InventoryMovementType::TransferIn])
                    ->count(),
            ),
        );
    }

    public function test_a_transfer_that_exceeds_available_stock_leaves_no_trace(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 2);
        $destination = Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'is_default' => false,
        ]);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/transfers', [
                'from_store_id' => $this->store->getKey(),
                'to_store_id' => $destination->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 5,
            ])
            ->assertStatus(409);

        $this->assertSame(2, $this->inventoryRow()->quantity);
        $this->assertSame(0, $this->withinTenant($this->tenant, fn () => StockTransfer::query()->count()));
        $this->assertSame(
            0,
            $this->withinTenant(
                $this->tenant,
                fn () => InventoryMovement::query()
                    ->whereIn('type', [InventoryMovementType::TransferOut, InventoryMovementType::TransferIn])
                    ->count(),
            ),
        );
    }

    public function test_a_store_belonging_to_another_tenant_cannot_be_a_transfer_endpoint(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $other = $this->tenantWithStore();
        $foreign = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $other->getKey())
            ->firstOrFail();

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/transfers', [
                'from_store_id' => $this->store->getKey(),
                'to_store_id' => $foreign->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
            ])
            ->assertUnprocessable();

        $this->assertSame(10, $this->inventoryRow()->quantity);
    }

    public function test_the_forecast_and_reorder_suggestion_explain_their_inputs(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 5);
        $this->withinTenant($this->tenant, fn () => $variant->forceFill(['cost' => 10])->save());
        $row = $this->inventoryRow();
        $this->backdateInventory($row, 35);
        $this->seedDailySales($row, days: 28, unitsPerDay: 2);

        $from = Carbon::now()->subDays(27)->toDateString();
        $until = Carbon::now()->toDateString();

        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/inventory/intelligence?from='.$from.'&until='.$until)
            ->assertOk()
            ->assertJsonPath('data.summary.items_count', 1)
            ->assertJsonPath('data.summary.units_sold', 56)
            ->assertJsonPath('data.items.0.forecast.status', 'estimated')
            ->assertJsonPath('data.items.0.forecast.daily_demand', 2)
            ->assertJsonPath('data.items.0.forecast.confidence', 'medium')
            ->assertJsonPath('data.items.0.forecast.observed_days', 28)
            ->assertJsonPath('data.items.0.replenishment.basis', 'forecast')
            ->assertJsonPath('data.items.0.replenishment.reorder_point', 20)
            ->assertJsonPath('data.items.0.replenishment.suggested_quantity', 43);

        $response->assertJsonPath('data.items.0.unit_cost', 10)
            ->assertJsonPath('data.items.0.cost_reliable', true)
            ->assertJsonPath('data.stores.0.store_id', $this->store->getKey());
    }

    public function test_without_enough_history_the_forecast_is_withheld_and_threshold_is_used(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 5);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/inventory/intelligence')
            ->assertOk()
            ->assertJsonPath('data.items.0.forecast.status', 'insufficient_history')
            ->assertJsonPath('data.items.0.forecast.daily_demand', null)
            ->assertJsonPath('data.items.0.forecast.confidence', 'low')
            ->assertJsonPath('data.items.0.replenishment.basis', 'threshold')
            ->assertJsonPath('data.items.0.replenishment.needed', true)
            ->assertJsonPath('data.items.0.replenishment.suggested_quantity', 1);
    }

    public function test_the_detail_endpoint_returns_a_daily_series(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $row = $this->inventoryRow();
        $this->seedDailySales($row, days: 2, unitsPerDay: 1);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/inventory/'.$row->getKey().'/intelligence')
            ->assertOk()
            ->assertJsonPath('data.item.id', $row->getKey())
            ->assertJsonCount(28, 'data.item.series')
            ->assertJsonPath('data.item.product_variant_id', $variant->getKey());
    }

    public function test_the_export_streams_a_csv_with_the_filtered_rows(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 7);

        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/v1/tenant/inventory/intelligence/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('Store,Product,Variant,SKU', $content);
        $this->assertStringContainsString('Default', $content);
    }

    private function inventoryRow(): Inventory
    {
        return Inventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
    }

    private function backdateInventory(Inventory $inventory, int $days): void
    {
        $this->withinTenant($this->tenant, function () use ($inventory, $days): void {
            $inventory->forceFill(['created_at' => Carbon::now()->subDays($days)])->save();
        });
    }

    private function seedDailySales(Inventory $inventory, int $days, int $unitsPerDay): void
    {
        $this->withinTenant($this->tenant, function () use ($inventory, $days, $unitsPerDay): void {
            for ($offset = 0; $offset < $days; $offset++) {
                InventoryMovement::query()->create([
                    'tenant_id' => $this->tenant->getKey(),
                    'inventory_id' => $inventory->getKey(),
                    'user_id' => $this->user->getKey(),
                    'type' => InventoryMovementType::Sale,
                    'quantity' => -$unitsPerDay,
                    'balance_after' => 5,
                    'source' => 'sale',
                    'created_at' => Carbon::now()->subDays($offset),
                ]);
            }
        });
    }
}
