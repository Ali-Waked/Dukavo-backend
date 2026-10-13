<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slug-addressed products.
 *
 * The catalogue URL is /products/{slug}, so the API must resolve the same way.
 * These tests pin down both halves of that contract: a slug the merchant chose
 * is honoured exactly, and every multi-tenant boundary still applies to it.
 */
class ProductSlugRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_product_is_created_with_the_chosen_slug_and_read_back_by_it(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Summer Sale',
                'slug' => 'Summer Sale',
                'price' => 10,
            ])
            ->assertCreated()
            // The submitted slug is normalised, never rewritten with a suffix.
            ->assertJsonPath('data.slug', 'summer-sale');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/products/summer-sale')
            ->assertOk()
            ->assertJsonPath('data.name', 'Summer Sale');
    }

    public function test_a_duplicate_slug_inside_the_same_tenant_is_rejected_with_422(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        Product::factory()->create(['tenant_id' => $tenant->getKey(), 'slug' => 'taken-slug']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Another',
                'slug' => 'taken-slug',
                'price' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('slug');
    }

    public function test_the_same_slug_may_exist_in_two_different_tenants(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();

        Product::factory()->create(['tenant_id' => $mine->getKey(), 'slug' => 'shared-slug']);

        $this->actingAs($this->memberOf($theirs))
            ->withHeader('X-Tenant', $theirs->slug)
            ->postJson('/api/v1/tenant/products', [
                'name' => 'Shared',
                'slug' => 'shared-slug',
                'price' => 5,
            ])
            ->assertCreated();
    }

    public function test_a_foreign_tenants_product_slug_is_not_found(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();

        Product::factory()->create(['tenant_id' => $theirs->getKey(), 'slug' => 'theirs-only']);

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/products/theirs-only')
            ->assertNotFound();
    }

    public function test_a_product_is_updated_and_deleted_by_slug(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $product = Product::factory()->create(['tenant_id' => $tenant->getKey(), 'slug' => 'editable']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/products/editable', ['name' => 'Renamed Product', 'slug' => 'editable'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Product')
            ->assertJsonPath('data.slug', 'editable');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson('/api/v1/tenant/products/editable')
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->getKey()]);
    }

    public function test_renaming_without_a_slug_regenerates_it_from_the_new_name(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        Product::factory()->create(['tenant_id' => $tenant->getKey(), 'slug' => 'old-name']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/products/old-name', ['name' => 'Brand New Name'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'brand-new-name');
    }

    public function test_slug_availability_reports_a_taken_slug_and_a_free_one(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        Product::factory()->create(['tenant_id' => $tenant->getKey(), 'slug' => 'claimed']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/products/slug-availability?slug=claimed')
            ->assertOk()
            ->assertJsonPath('data.available', false);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/products/slug-availability?slug=free')
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }

    public function test_slug_availability_ignores_another_tenants_slug(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();

        Product::factory()->create(['tenant_id' => $theirs->getKey(), 'slug' => 'theirs-slug']);

        $this->actingAs($this->memberOf($mine))
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/products/slug-availability?slug=theirs-slug')
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }

    public function test_slug_availability_ignores_the_product_being_edited(): void
    {
        $tenant = $this->tenantWithStore();
        $user = $this->memberOf($tenant);

        $product = Product::factory()->create(['tenant_id' => $tenant->getKey(), 'slug' => 'kept-slug']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/products/slug-availability?slug=kept-slug&ignore='.$product->getKey())
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }
}
