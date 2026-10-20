<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a product — variants and opening stock.
 *
 * Two guarantees are pinned here. First, every product comes out of creation
 * with a variant set (the server builds a default when none is sent), because a
 * product with no variant cannot be ordered or stocked. Second, opening stock a
 * merchant types at creation is written to the append-only ledger, so the
 * on-hand figure is always explainable rather than conjured from nothing.
 */
class ProductCreationTest extends TestCase
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

    public function test_opening_stock_is_written_to_the_ledger(): void
    {
        $response = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Opening Stock Tee',
                'price' => 10,
                'variants' => [[
                    'name' => 'Default',
                    'price' => 10,
                    'is_default' => true,
                    'initial_quantity' => 7,
                ]],
            ])
            ->assertCreated();

        $variantId = $response->json('data.variants.0.id');

        $this->withinTenant($this->tenant, function () use ($variantId): void {
            $inventory = Inventory::query()
                ->where('product_variant_id', $variantId)
                ->firstOrFail();

            $this->assertSame(7, $inventory->quantity);
            $this->assertSame($this->store->getKey(), $inventory->store_id);

            $movement = InventoryMovement::query()->where('inventory_id', $inventory->getKey())->firstOrFail();

            $this->assertSame(InventoryMovementType::Initial, $movement->type);
            $this->assertSame(7, $movement->quantity);
            $this->assertSame(7, $movement->balance_after);
        });
    }

    public function test_a_variant_with_no_opening_stock_still_gets_an_inventory_row(): void
    {
        $response = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Empty Shelf',
                'price' => 10,
                'variants' => [[
                    'name' => 'Default',
                    'price' => 10,
                    'is_default' => true,
                    'initial_quantity' => 0,
                ]],
            ])
            ->assertCreated();

        $variantId = $response->json('data.variants.0.id');

        $this->withinTenant($this->tenant, function () use ($variantId): void {
            $inventory = Inventory::query()->where('product_variant_id', $variantId)->firstOrFail();

            $this->assertSame(0, $inventory->quantity);
            // Zero opening stock is not a movement; the row alone is the record.
            $this->assertSame(0, InventoryMovement::query()->where('inventory_id', $inventory->getKey())->count());
        });
    }

    public function test_each_variant_receives_its_own_opening_stock(): void
    {
        $response = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Multi Size Tee',
                'price' => 10,
                'variants' => [
                    ['name' => 'Small', 'price' => 10, 'is_default' => true, 'initial_quantity' => 3],
                    ['name' => 'Large', 'price' => 12, 'initial_quantity' => 5],
                ],
            ])
            ->assertCreated();

        $small = $response->json('data.variants.0.id');
        $large = $response->json('data.variants.1.id');

        $this->withinTenant($this->tenant, function () use ($small, $large): void {
            $quantities = Inventory::query()
                ->whereIn('product_variant_id', [$small, $large])
                ->pluck('quantity', 'product_variant_id');

            // The submitted order is preserved, so the right quantity lands on
            // the right variant rather than being shuffled.
            $this->assertSame(3, $quantities[$small]);
            $this->assertSame(5, $quantities[$large]);
        });
    }

    public function test_opening_stock_lands_in_the_tenants_default_store(): void
    {
        $response = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Storeless Product',
                'price' => 10,
                'variants' => [[
                    'name' => 'Default',
                    'price' => 10,
                    'is_default' => true,
                    'initial_quantity' => 4,
                ]],
            ])
            ->assertCreated();

        $variantId = $response->json('data.variants.0.id');

        $this->withinTenant($this->tenant, function () use ($variantId): void {
            $inventory = Inventory::query()->where('product_variant_id', $variantId)->firstOrFail();
            $this->assertSame($this->store->getKey(), $inventory->store_id);
        });
    }

    public function test_a_product_without_variants_is_given_a_default_one(): void
    {
        $response = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Simple Widget',
                'price' => 15,
            ])
            ->assertCreated();

        $this->assertCount(1, $response->json('data.variants'));
        $this->assertTrue($response->json('data.variants.0.is_default'));
    }

    public function test_updating_a_product_keeps_existing_variants_and_their_stock(): void
    {
        $user = $this->memberOf($this->tenant);

        $created = $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Editable Product',
                'price' => 10,
                'variants' => [[
                    'name' => 'Default',
                    'price' => 10,
                    'is_default' => true,
                    'initial_quantity' => 6,
                ]],
            ])
            ->assertCreated();

        $variantId = $created->json('data.variants.0.id');
        $slug = $created->json('data.slug');

        // Sending the variant id back keeps the row — and its inventory — in
        // place instead of recreating it.
        $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson('/api/v1/tenant/products/'.$slug, [
                'name' => 'Editable Product Renamed',
                'variants' => [[
                    'id' => $variantId,
                    'name' => 'Default',
                    'price' => 11,
                    'is_default' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.variants.0.id', $variantId);

        $this->withinTenant($this->tenant, function () use ($variantId): void {
            $inventory = Inventory::query()->where('product_variant_id', $variantId)->firstOrFail();
            $this->assertSame(6, $inventory->quantity);
        });
    }

    public function test_the_show_endpoint_reports_per_variant_and_total_stock(): void
    {
        $slug = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Stocked Product',
                'price' => 10,
                'variants' => [
                    ['name' => 'Small', 'price' => 10, 'is_default' => true, 'initial_quantity' => 3],
                    ['name' => 'Large', 'price' => 12, 'initial_quantity' => 5],
                ],
            ])
            ->assertCreated()
            ->json('data.slug');

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/products/'.$slug)
            ->assertOk()
            ->assertJsonPath('data.variants.0.stock.quantity', 3)
            ->assertJsonPath('data.variants.1.stock.quantity', 5)
            ->assertJsonPath('data.stock.quantity', 8);
    }
}
