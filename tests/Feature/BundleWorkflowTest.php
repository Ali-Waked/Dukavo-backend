<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Bundle;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bundles\BundleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server is the only authority for bundle pricing and availability.
 *
 * These tests deliberately edit inventory and catalogue rows between previews:
 * a bundle is a live proposal, so a preview that was true a minute ago must not
 * be trusted after stock changes.
 */
class BundleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_a_merchant_can_create_a_bundle_and_the_server_calculates_the_discount(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);

        $created = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.store'), [
                'name' => 'Starter pair',
                'status' => 'active',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'store_id' => $this->store->getKey(),
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey(), 'quantity' => 2],
                    ['product_id' => $second['product']->getKey(), 'product_variant_id' => $second['variant']->getKey(), 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Starter pair')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.item_count', 2);

        $preview = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', $created->json('data.id')))
            ->assertOk()
            ->assertJsonPath('data.subtotal', 30)
            ->assertJsonPath('data.discount', 3)
            ->assertJsonPath('data.total', 27)
            ->assertJsonPath('data.is_available', true)
            ->json('data');

        $this->assertNotEmpty($preview['catalog_fingerprint']);
        $this->assertCount(2, $preview['items']);
    }

    public function test_a_stale_preview_is_refused_after_stock_changes(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store, 10);
        $second = $this->stockedProduct($this->tenant, $this->store, 10);
        $bundle = $this->bundle($first, $second);

        $fingerprint = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', $bundle))
            ->assertOk()
            ->json('data.catalog_fingerprint');

        Inventory::query()
            ->withoutGlobalScopes()
            ->where('product_variant_id', $first['variant']->getKey())
            ->update(['quantity' => 0]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', [
                'bundle' => $bundle->getKey(),
                'expected_catalog_fingerprint' => $fingerprint,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_bundle_preview');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', $bundle))
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.issues.0.code', 'insufficient_stock');
    }

    public function test_replacing_bundle_lines_invalidates_an_earlier_preview(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);
        $third = $this->stockedProduct($this->tenant, $this->store);
        $bundle = $this->bundle($first, $second);

        $fingerprint = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', $bundle))
            ->assertOk()
            ->json('data.catalog_fingerprint');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.bundles.update', $bundle), [
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                    ['product_id' => $third['product']->getKey(), 'product_variant_id' => $third['variant']->getKey()],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', [
                'bundle' => $bundle->getKey(),
                'expected_catalog_fingerprint' => $fingerprint,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_bundle_preview');
    }

    public function test_a_variant_that_belongs_to_another_product_is_rejected(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.store'), [
                'name' => 'Wrong variant',
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                    ['product_id' => $second['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.product_variant_id']);
    }

    public function test_a_bundle_is_unavailable_when_a_product_is_not_sold_in_the_selected_store(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);
        $bundle = $this->bundle($first, $second);

        $otherStore = $this->withinTenant($this->tenant, fn (): Store => Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'is_default' => false,
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', [
                'bundle' => $bundle->getKey(),
                'store_id' => $otherStore->getKey(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonFragment(['code' => 'product_not_in_store']);
    }

    public function test_duplicate_variants_and_percentage_discounts_above_one_hundred_are_refused(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.store'), [
                'name' => 'Duplicate',
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_bundle_variant');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.store'), [
                'name' => 'Too generous',
                'discount_type' => 'percentage',
                'discount_value' => 101,
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                    ['product_id' => $second['product']->getKey(), 'product_variant_id' => $second['variant']->getKey()],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_bundle_discount');
    }

    public function test_a_fixed_discount_is_capped_at_the_subtotal(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);
        $bundle = $this->bundle($first, $second, ['discount_type' => 'fixed', 'discount_value' => 1000]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', $bundle))
            ->assertOk()
            ->assertJsonPath('data.subtotal', 20)
            ->assertJsonPath('data.discount', 20)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.is_available', true);
    }

    public function test_an_inactive_product_makes_the_bundle_unavailable(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);

        $this->withinTenant($this->tenant, fn () => $first['product']->update(['status' => ProductStatus::Draft]));

        $bundle = $this->bundle($first, $second);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.preview', $bundle))
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonFragment(['code' => 'product_inactive']);
    }

    public function test_a_bundle_from_another_tenant_is_not_visible(): void
    {
        $otherTenant = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->getKey())
            ->firstOrFail();

        $foreign = $this->withinTenant($otherTenant, fn (): Bundle => Bundle::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'store_id' => $otherStore->getKey(),
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.show', $foreign))
            ->assertNotFound();

        $foreignProduct = $this->stockedProduct($otherTenant, $otherStore);
        $first = $this->stockedProduct($this->tenant, $this->store);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.store'), [
                'name' => 'Foreign stock',
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                    ['product_id' => $foreignProduct['product']->getKey(), 'product_variant_id' => $foreignProduct['variant']->getKey()],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.product_id']);
    }

    public function test_a_merchant_can_update_replace_items_and_archive_a_bundle(): void
    {
        $first = $this->stockedProduct($this->tenant, $this->store);
        $second = $this->stockedProduct($this->tenant, $this->store);
        $third = $this->stockedProduct($this->tenant, $this->store);
        $bundle = $this->bundle($first, $second);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.bundles.update', $bundle), [
                'name' => 'Updated bundle',
                'discount_value' => 5,
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey()],
                    ['product_id' => $third['product']->getKey(), 'product_variant_id' => $third['variant']->getKey()],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated bundle')
            ->assertJsonPath('data.discount_value', 5)
            ->assertJsonPath('data.item_count', 2);

        $this->assertDatabaseMissing('bundle_items', [
            'bundle_id' => $bundle->getKey(),
            'product_variant_id' => $second['variant']->getKey(),
        ]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson(route('api.tenant.bundles.destroy', $bundle))
            ->assertNoContent();

        $this->assertSoftDeleted('bundles', ['id' => $bundle->getKey()]);
    }

    /**
     * @param  array{product: Product, variant: ProductVariant, store: Store}  $first
     * @param  array{product: Product, variant: ProductVariant, store: Store}  $second
     * @param  array<string, mixed>  $attributes
     */
    private function bundle(array $first, array $second, array $attributes = []): Bundle
    {
        return $this->withinTenant($this->tenant, function () use ($first, $second, $attributes): Bundle {
            $bundle = Bundle::factory()->active()->create([
                'tenant_id' => $this->tenant->getKey(),
                'store_id' => $this->store->getKey(),
                'discount_type' => 'percentage',
                'discount_value' => 10,
                ...$attributes,
            ]);

            $this->app->make(BundleService::class)->update($bundle, [
                'items' => [
                    ['product_id' => $first['product']->getKey(), 'product_variant_id' => $first['variant']->getKey(), 'quantity' => 1],
                    ['product_id' => $second['product']->getKey(), 'product_variant_id' => $second['variant']->getKey(), 'quantity' => 1],
                ],
            ]);

            return $bundle->refresh();
        });
    }
}
