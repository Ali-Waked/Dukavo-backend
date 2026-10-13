<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Customer;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_not_referenced_by_orders_is_soft_deleted(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = $this->tenantWithStore();
        $store = $tenant->stores()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $user = $this->memberOf($tenant);

        ['product' => $product] = $this->stockedProduct($tenant, $store);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson('/api/v1/tenant/products/'.$product->slug)
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->getKey()]);
        $this->assertSame(ProductStatus::Active->value, $product->fresh()->status->value);
    }

    public function test_a_product_referenced_by_orders_is_archived_not_deleted(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = $this->tenantWithStore();
        $store = $tenant->stores()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $user = $this->memberOf($tenant);
        $customer = Customer::factory()->for($tenant)->create();

        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($tenant, $store, quantity: 10);

        $this->withinTenant($tenant, function () use ($store, $customer, $variant): void {
            app(OrderService::class)->create([
                'store_id' => $store->getKey(),
                'customer_id' => $customer->getKey(),
                'items' => [[
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 1,
                    'price' => $variant->price,
                ]],
            ]);
        });

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson('/api/v1/tenant/products/'.$product->slug)
            ->assertNoContent();

        $this->assertDatabaseHas('products', ['id' => $product->getKey(), 'deleted_at' => null]);
        $this->assertSame(ProductStatus::Archived->value, $product->fresh()->status->value);
    }

    public function test_forcing_deletion_of_ordered_product_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = $this->tenantWithStore();
        $store = $tenant->stores()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $user = $this->memberOf($tenant);
        $customer = Customer::factory()->for($tenant)->create();

        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($tenant, $store, quantity: 5);

        $this->withinTenant($tenant, function () use ($store, $customer, $variant): void {
            app(OrderService::class)->create([
                'store_id' => $store->getKey(),
                'customer_id' => $customer->getKey(),
                'items' => [[
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 1,
                    'price' => $variant->price,
                ]],
            ]);
        });

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson('/api/v1/tenant/products/'.$product->slug, ['force' => true])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_force_deleting_an_unreferenced_product_purges_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = $this->tenantWithStore();
        $store = $tenant->stores()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $user = $this->memberOf($tenant);

        ['product' => $product] = $this->stockedProduct($tenant, $store);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson('/api/v1/tenant/products/'.$product->slug, ['force' => true])
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->getKey()]);
    }
}
