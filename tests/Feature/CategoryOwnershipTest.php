<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category ownership and slug uniqueness.
 *
 * A category id is a foreign key a client can guess. Validation must reject an
 * id that belongs to another tenant at the request boundary (422), not let it
 * reach the service and fail as a business-rule conflict (409), because "that
 * category is not yours" and "that move is illegal" are different answers.
 */
class CategoryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_product_cannot_be_created_with_another_tenants_category(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();

        $foreign = Category::factory()->create(['tenant_id' => $theirs->getKey()]);

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Sneaky Product',
                'price' => 10,
                'category_id' => $foreign->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_a_product_may_be_created_with_its_own_tenants_category(): void
    {
        $mine = $this->tenantWithStore();
        $category = Category::factory()->create(['tenant_id' => $mine->getKey()]);

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Legitimate Product',
                'price' => 10,
                'category_id' => $category->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.category.id', $category->getKey());
    }

    public function test_a_category_can_be_created_with_a_chosen_slug(): void
    {
        $tenant = $this->tenantWithStore();

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/categories', [
                'name' => 'Winter Wear',
                'slug' => 'Winter Wear',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'winter-wear');
    }

    public function test_a_category_slug_must_be_unique_inside_the_tenant(): void
    {
        $tenant = $this->tenantWithStore();

        Category::factory()->create(['tenant_id' => $tenant->getKey(), 'slug' => 'duplicated']);

        $this->actingAs($this->memberOf($tenant))
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/categories', [
                'name' => 'Duplicated',
                'slug' => 'duplicated',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_category_slug_availability_is_scoped_to_the_tenant(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();

        Category::factory()->create(['tenant_id' => $theirs->getKey(), 'slug' => 'theirs-category']);

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/categories/slug-availability?slug=theirs-category')
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }
}
