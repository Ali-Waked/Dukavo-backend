<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Shared helpers for building the world a test acts on.
 *
 * Tenant-owned models refuse to be created without a resolved tenant, which is
 * the behaviour that makes the application safe. Tests therefore never rely on
 * the ambient context: they create rows through the factories and, when they
 * exercise a service directly, wrap the call in `withinTenant()`.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user who holds `$roleSlug` inside `$tenant`.
     */
    protected function memberOf(Tenant $tenant, string $roleSlug = 'merchant'): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create();

        $user->tenants()->attach($tenant->getKey(), ['is_owner' => $roleSlug === 'merchant']);

        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);

        return $user->fresh();
    }

    /**
     * Create a user who holds a platform role (no tenant).
     */
    protected function platformUser(string $roleSlug = 'super-admin'): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create();

        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => null,
        ]);

        return $user->fresh();
    }

    /**
     * A tenant with one active default store.
     */
    protected function tenantWithStore(array $attributes = []): Tenant
    {
        $tenant = Tenant::factory()->create($attributes);

        Store::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'is_default' => true,
        ]);

        return $tenant;
    }

    /**
     * Run a closure as if it were handling a request for `$tenant`.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withinTenant(Tenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantContext::class)->run($tenant, $callback);
    }

    /**
     * A stocked product with one default variant.
     *
     * The variant is returned as well as the product: reading it back through the
     * relation from outside the tenant context would find nothing, which is the
     * isolation guarantee rather than a bug to work around.
     *
     * @return array{product: Product, variant: ProductVariant, store: Store}
     */
    protected function stockedProduct(Tenant $tenant, Store $store, int $quantity = 20): array
    {
        $product = $this->withinTenant($tenant, fn () => Product::factory()
            ->atStore($store)
            ->create([
                'tenant_id' => $tenant->getKey(),
                'price' => 10.00,
            ]));

        $variant = $this->withinTenant($tenant, fn () => $product->variants()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Default',
            'sku' => 'SKU-'.$product->getKey(),
            'price' => 10.00,
            'is_default' => true,
        ]));

        $this->withinTenant($tenant, fn () => $variant->inventories()->create([
            'tenant_id' => $tenant->getKey(),
            'store_id' => $store->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'low_stock_threshold' => 5,
        ]));

        return [
            'product' => $product,
            'variant' => $variant->refresh(),
            'store' => $store,
        ];
    }

    /**
     * Assert a factory-built model belongs to the tenant under test, failing the
     * test rather than the framework if the helper above was misused.
     */
    protected function assertBelongsToTenant(Tenant $tenant, Customer|Order|Product|Store $model): void
    {
        $this->assertSame($tenant->getKey(), $model->tenant_id);
    }
}
