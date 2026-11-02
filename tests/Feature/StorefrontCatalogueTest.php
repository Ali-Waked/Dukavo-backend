<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public side of a tenant's shopfront.
 *
 * These tests exist to prove the two things a public endpoint must never get
 * wrong: an unpublished shop is invisible, and a shopper cannot see stock or
 * catalogue rows that belong to another store or another tenant.
 */
class StorefrontCatalogueTest extends TestCase
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

        $this->store->forceFill([
            'storefront_enabled' => true,
            'description' => 'A small shop.',
            'storefront_settings' => ['tagline' => 'Fresh every day'],
        ])->save();
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.$path;
    }

    public function test_a_published_storefront_serves_its_settings_and_products(): void
    {
        ['product' => $product] = $this->stockedProduct($this->tenant, $this->store, quantity: 4);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.name', $this->store->name)
            ->assertJsonPath('data.slug', $this->store->slug)
            ->assertJsonPath('data.tenant_slug', $this->tenant->slug)
            ->assertJsonPath('data.description', 'A small shop.')
            ->assertJsonPath('data.settings.tagline', 'Fresh every day')
            ->assertJsonPath('data.currency', 'USD');

        $this->getJson($this->url('/products'))
            ->assertOk()
            ->assertJsonPath('data.0.slug', $product->slug)
            ->assertJsonPath('data.0.price', 10)
            ->assertJsonPath('data.0.in_stock', true)
            ->assertJsonPath('data.0.available', 4)
            ->assertJsonPath('data.0.variants.0.price', 10)
            ->assertJsonPath('data.0.variants.0.available', 4);
    }

    public function test_a_store_that_has_not_published_its_storefront_is_a_404(): void
    {
        $this->store->forceFill(['storefront_enabled' => false])->save();

        $this->getJson($this->url())->assertNotFound();
        $this->getJson($this->url('/products'))->assertNotFound();
    }

    public function test_an_inactive_store_is_a_404_even_when_storefront_is_enabled(): void
    {
        $this->store->forceFill(['status' => StoreStatus::Inactive])->save();

        $this->getJson($this->url())->assertNotFound();
    }

    public function test_a_suspended_tenant_is_a_404(): void
    {
        $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->getJson($this->url())->assertNotFound();
    }

    public function test_a_product_assigned_to_a_sibling_store_is_hidden(): void
    {
        $sibling = Store::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        ['product' => $hidden] = $this->stockedProduct($this->tenant, $sibling);
        ['product' => $visible] = $this->stockedProduct($this->tenant, $this->store);

        $response = $this->getJson($this->url('/products'))->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug');

        $this->assertTrue($slugs->contains($visible->slug));
        $this->assertFalse($slugs->contains($hidden->slug));

        $this->getJson($this->url('/products/'.$hidden->slug))->assertNotFound();
    }

    public function test_a_shared_product_is_visible_in_every_store_of_the_tenant(): void
    {
        $product = $this->withinTenant($this->tenant, fn (): Product => Product::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => null,
            'status' => ProductStatus::Active,
            'price' => 12.50,
        ]));

        $this->withinTenant($this->tenant, fn () => $product->variants()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Default',
            'sku' => 'SHARED-'.$product->getKey(),
            'price' => 12.50,
            'is_default' => true,
        ]));

        $this->getJson($this->url('/products/'.$product->slug))
            ->assertOk()
            ->assertJsonPath('data.slug', $product->slug)
            ->assertJsonPath('data.price', 12.5);
    }

    public function test_categories_only_count_products_this_store_can_sell(): void
    {
        $sibling = Store::factory()->create(['tenant_id' => $this->tenant->getKey()]);

        $category = $this->withinTenant($this->tenant, fn (): Category => Category::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'is_active' => true,
        ]));

        $visible = $this->withinTenant($this->tenant, fn (): Product => Product::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'category_id' => $category->getKey(),
        ]));

        $this->withinTenant($this->tenant, fn (): Product => Product::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $sibling->getKey(),
            'category_id' => $category->getKey(),
        ]));

        $response = $this->getJson($this->url('/categories'))->assertOk();

        $this->assertSame($category->slug, $response->json('data.0.slug'));
        $this->assertSame(1, $response->json('data.0.products_count'));
        $this->assertNotNull($visible->slug);
    }

    public function test_an_unknown_product_is_a_404(): void
    {
        $this->getJson($this->url('/products/does-not-exist'))->assertNotFound();
    }
}
