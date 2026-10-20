<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product counts on categories.
 *
 * The catalogue navigation shows how many products sit in each category. The
 * count must come from the database with the same rules the products list uses:
 * archived (soft-deleted) products are gone, and another tenant's products are
 * invisible — a count that disagrees with the list it labels is worse than no
 * count at all.
 */
class CategoryProductCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_index_reports_how_many_products_a_category_holds(): void
    {
        $tenant = $this->tenantWithStore();
        $category = Category::factory()->create(['tenant_id' => $tenant->getKey()]);

        Product::factory()->count(2)->create([
            'tenant_id' => $tenant->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/categories')
            ->assertOk()
            ->assertJsonPath('data.0.products_count', 2);
    }

    public function test_soft_deleted_products_are_not_counted(): void
    {
        $tenant = $this->tenantWithStore();
        $category = Category::factory()->create(['tenant_id' => $tenant->getKey()]);

        $products = Product::factory()->count(3)->create([
            'tenant_id' => $tenant->getKey(),
            'category_id' => $category->getKey(),
        ]);

        // Archiving drops a product out of the catalogue, so it must drop out
        // of the category's count as well.
        $products->first()->delete();

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/categories')
            ->assertOk()
            ->assertJsonPath('data.0.products_count', 2);
    }

    public function test_the_tree_counts_products_on_nested_nodes(): void
    {
        $tenant = $this->tenantWithStore();
        $parent = Category::factory()->create(['tenant_id' => $tenant->getKey()]);
        $child = Category::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'parent_id' => $parent->getKey(),
        ]);

        Product::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'category_id' => $child->getKey(),
        ]);
        Product::factory()->count(2)->create([
            'tenant_id' => $tenant->getKey(),
            'category_id' => $parent->getKey(),
        ]);

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/categories/tree')
            ->assertOk()
            ->assertJsonPath('data.0.id', $parent->getKey())
            ->assertJsonPath('data.0.products_count', 2)
            ->assertJsonPath('data.0.children.0.id', $child->getKey())
            ->assertJsonPath('data.0.children.0.products_count', 1);
    }

    public function test_another_tenants_products_are_never_counted(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();

        $category = Category::factory()->create(['tenant_id' => $mine->getKey()]);
        Category::factory()->create(['tenant_id' => $theirs->getKey()]);

        // Products in the *other* tenant that happen to point at the same
        // category id must not leak into my count.
        Product::factory()->count(4)->create([
            'tenant_id' => $theirs->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/categories')
            ->assertOk()
            ->assertJsonPath('data.0.products_count', 0);
    }
}
