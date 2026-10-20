<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Stock across the stores of a single tenant.
 *
 * A tenant owns many stores, a product belongs to the tenant (optionally to one
 * "home" store), and stock always lives against a concrete store/variant pair.
 * These tests pin down the behaviour that makes the multi-store catalogue safe:
 * opening stock lands somewhere reachable, a second store can be stocked
 * independently, and editing a product never disturbs the numbers.
 */
class MultiStoreInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A tenant with a default store plus a second, non-default store.
     *
     * @return array{tenant: Tenant, primary: Store, secondary: Store, user: User}
     */
    private function makeMerchantWithTwoStores(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $tenant = $this->tenantWithStore();
        $primary = $this->storeFor($tenant, default: true);

        $secondary = Store::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'is_default' => false,
        ]);

        return [
            'tenant' => $tenant,
            'primary' => $primary,
            'secondary' => $secondary,
            'user' => $this->memberOf($tenant),
        ];
    }

    private function storeFor(Tenant $tenant, bool $default = false): Store
    {
        return Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_default', $default)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, Inventory>
     */
    private function inventoryRows(Tenant $tenant): Collection
    {
        return $this->withinTenant(
            $tenant,
            fn (): Collection => Inventory::query()->orderBy('product_variant_id')->orderBy('store_id')->get(),
        );
    }

    private function createProduct(Tenant $tenant, User $user, array $payload): Product
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/products', $payload);

        $response->assertCreated();

        return $this->withinTenant(
            $tenant,
            fn (): Product => Product::query()->where('slug', $response->json('data.slug'))->firstOrFail(),
        );
    }

    public function test_opening_stock_lands_in_the_default_store_and_a_second_store_can_be_stocked_independently(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'secondary' => $secondary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Wireless Kettle',
            'price' => 40,
            'variants' => [
                ['name' => 'Default', 'price' => 40, 'is_default' => true, 'initial_quantity' => 100],
            ],
        ]);

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/inventory/receive', [
                'store_id' => $secondary->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 30,
            ])
            ->assertCreated();

        $rows = $this->inventoryRows($tenant)->keyBy('store_id');

        $this->assertCount(2, $rows);
        $this->assertSame(100, $rows[$primary->getKey()]->quantity);
        $this->assertSame(30, $rows[$secondary->getKey()]->quantity);
        $this->assertSame($variant->getKey(), $rows[$secondary->getKey()]->product_variant_id);
    }

    public function test_opening_stock_can_be_directed_to_a_chosen_store_at_creation(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'secondary' => $secondary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Branch Widget',
            'store_id' => $secondary->getKey(),
            'variants' => [
                ['name' => 'Default', 'price' => 10, 'is_default' => true, 'initial_quantity' => 8],
            ],
        ]);

        $this->assertSame($secondary->getKey(), $product->store_id);

        $rows = $this->inventoryRows($tenant)->keyBy('store_id');

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey($secondary->getKey(), $rows);
        $this->assertArrayNotHasKey($primary->getKey(), $rows);
        $this->assertSame(8, $rows[$secondary->getKey()]->quantity);
    }

    public function test_a_second_receipt_into_the_same_store_adds_to_the_existing_balance(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Desk Lamp',
            'variants' => [
                ['name' => 'Default', 'price' => 20, 'is_default' => true, 'initial_quantity' => 5],
            ],
        ]);

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        foreach ([20, 10] as $quantity) {
            $this->actingAs($user)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/v1/tenant/inventory/receive', [
                    'store_id' => $primary->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => $quantity,
                ])
                ->assertCreated();
        }

        $rows = $this->inventoryRows($tenant);

        // One row per store/variant pair; the receipts stack onto it.
        $this->assertCount(1, $rows);
        $this->assertSame(35, $rows->first()->quantity);
    }

    public function test_adjusting_stock_in_one_store_leaves_the_other_store_untouched(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'secondary' => $secondary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Desk Fan',
            'variants' => [
                ['name' => 'Default', 'price' => 20, 'is_default' => true, 'initial_quantity' => 100],
            ],
        ]);

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/inventory/receive', [
                'store_id' => $secondary->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 30,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/inventory/adjust', [
                'store_id' => $primary->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 110,
                'note' => 'Stock take',
            ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.balance_after', 110);

        $rows = $this->inventoryRows($tenant)->keyBy('store_id');

        $this->assertSame(110, $rows[$primary->getKey()]->quantity);
        $this->assertSame(30, $rows[$secondary->getKey()]->quantity);
    }

    public function test_editing_a_product_does_not_disturb_its_stock(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Old Name',
            'variants' => [
                ['name' => 'Default', 'price' => 20, 'is_default' => true, 'initial_quantity' => 42],
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/products/'.$product->slug, ['name' => 'New Name'])
            ->assertOk();

        $rows = $this->inventoryRows($tenant);

        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows->first()->quantity);
        $this->assertSame($primary->getKey(), $rows->first()->store_id);
    }

    public function test_each_variant_keeps_its_own_stock_and_store(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'secondary' => $secondary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'T-Shirt',
            'variants' => [
                ['name' => 'Small', 'price' => 15, 'is_default' => true, 'initial_quantity' => 5],
                ['name' => 'Large', 'price' => 17, 'initial_quantity' => 7],
            ],
        ]);

        $variants = $this->withinTenant($tenant, fn () => $product->variants()->orderBy('position')->get());
        $large = $variants->firstWhere('name', 'Large');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/inventory/receive', [
                'store_id' => $secondary->getKey(),
                'product_variant_id' => $large->getKey(),
                'quantity' => 3,
            ])
            ->assertCreated();

        $rows = $this->inventoryRows($tenant)->keyBy(fn (Inventory $row): string => $row->product_variant_id.'-'.$row->store_id);
        $small = $variants->firstWhere('name', 'Small');

        $this->assertSame(5, $rows[$small->getKey().'-'.$primary->getKey()]->quantity);
        $this->assertSame(7, $rows[$large->getKey().'-'.$primary->getKey()]->quantity);
        $this->assertSame(3, $rows[$large->getKey().'-'.$secondary->getKey()]->quantity);
    }

    public function test_a_product_created_with_zero_stock_is_still_listed_in_inventory(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Preorder Widget',
            'variants' => [
                ['name' => 'Default', 'price' => 10, 'is_default' => true, 'initial_quantity' => 0],
            ],
        ]);

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/inventory')
            ->assertOk()
            ->assertJsonFragment(['product_variant_id' => $variant->getKey(), 'quantity' => 0]);
    }

    public function test_the_product_list_reports_stock_for_each_product(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Listed Widget',
            'status' => 'active',
            'variants' => [
                ['name' => 'Default', 'price' => 10, 'is_default' => true, 'initial_quantity' => 12],
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->getKey())
            ->assertJsonPath('data.0.stock.quantity', 12)
            ->assertJsonPath('data.0.stock.available', 12);
    }

    public function test_a_variant_added_by_an_edit_receives_a_zero_stock_row(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Hoodie',
            'variants' => [
                ['name' => 'Small', 'price' => 30, 'is_default' => true, 'initial_quantity' => 4],
            ],
        ]);

        $small = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/products/'.$product->slug, [
                'variants' => [
                    ['id' => $small->getKey(), 'name' => 'Small', 'price' => 30, 'is_default' => true],
                    ['name' => 'Large', 'price' => 32],
                ],
            ])
            ->assertOk();

        $large = $this->withinTenant($tenant, fn () => $product->variants()->where('name', 'Large')->firstOrFail());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/inventory')
            ->assertOk()
            ->assertJsonFragment([
                'product_variant_id' => $large->getKey(),
                'store_id' => $primary->getKey(),
                'quantity' => 0,
            ]);
    }

    public function test_a_receipt_into_another_tenants_store_is_rejected_with_404(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Something',
            'variants' => [
                ['name' => 'Default', 'price' => 10, 'is_default' => true, 'initial_quantity' => 1],
            ],
        ]);

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        $other = $this->tenantWithStore();
        $foreignStore = $this->storeFor($other, default: true);

        // The store id exists, but not for this tenant, so the controller's
        // tenant-scoped lookup resolves it as missing rather than revealing it.
        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/inventory/receive', [
                'store_id' => $foreignStore->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
            ])
            ->assertNotFound();
    }

    public function test_adjusting_below_committed_stock_is_refused_and_leaves_no_movement(): void
    {
        ['tenant' => $tenant, 'primary' => $primary, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $product = $this->createProduct($tenant, $user, [
            'name' => 'Reserved Widget',
            'variants' => [
                ['name' => 'Default', 'price' => 10, 'is_default' => true, 'initial_quantity' => 10],
            ],
        ]);

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->firstOrFail());

        $this->withinTenant($tenant, function () use ($variant): void {
            $row = Inventory::query()->where('product_variant_id', $variant->getKey())->firstOrFail();
            app(InventoryService::class)->reserve($row, 6);
        });

        $movementsBefore = $this->withinTenant($tenant, fn () => InventoryMovement::query()->count());

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/inventory/adjust', [
                'store_id' => $primary->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
            ])
            ->assertStatus(409);

        $movementsAfter = $this->withinTenant($tenant, fn () => InventoryMovement::query()->count());

        $this->assertSame($movementsBefore, $movementsAfter);
        $this->assertSame(10, $this->inventoryRows($tenant)->first()->quantity);
    }

    public function test_inventory_of_another_tenant_is_never_listed(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->makeMerchantWithTwoStores();

        $other = $this->tenantWithStore();
        $otherUser = $this->memberOf($other);
        $otherStore = $this->storeFor($other, default: true);

        $this->stockedProduct($other, $otherStore, quantity: 77);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/inventory')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($otherUser)
            ->withHeader('X-Tenant', $other->slug)
            ->getJson('/api/v1/tenant/inventory')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
