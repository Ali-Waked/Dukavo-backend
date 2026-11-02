<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\StoreStatus;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Public storefront reads.
 *
 * The caller resolves the tenant from the URL slug *before* entering this
 * service, and every method assumes it is already running inside
 * {@see TenantContext::run()}. That is what lets the normal
 * tenant global scope protect these queries instead of re-implementing the
 * isolation filter on a public endpoint.
 */
class StorefrontService
{
    public function resolveTenant(string $tenantSlug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if (! $tenant instanceof Tenant || $tenant->status !== TenantStatus::Active) {
            throw new ModelNotFoundException('This storefront is not available.');
        }

        return $tenant;
    }

    public function resolveStore(Tenant $tenant, string $storeSlug): Store
    {
        $store = Store::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('slug', $storeSlug)
            ->where('storefront_enabled', true)
            ->where('status', StoreStatus::Active)
            ->first();

        if (! $store instanceof Store) {
            throw new ModelNotFoundException('This storefront is not available.');
        }

        return $store;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(Store $store): array
    {
        return StorefrontSettings::merge($store->storefront_settings);
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(Store $store): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $this->availableProductQuery($query, $store)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{search?: string|null, category?: string|null, sort?: string|null, per_page?: int|null}  $filters
     */
    public function products(Store $store, array $filters = []): LengthAwarePaginator
    {
        $query = Product::query()
            ->active()
            ->where(fn ($inner) => $this->availableProductQuery($inner, $store))
            ->with([
                'category',
                'images',
                'variants.inventories' => fn ($inventory) => $inventory->where('store_id', $store->getKey()),
                'inventories' => fn ($inventory) => $inventory->where('store_id', $store->getKey()),
            ]);

        if (($filters['search'] ?? null) !== null && $filters['search'] !== '') {
            $query->search((string) $filters['search']);
        }

        if (($filters['category'] ?? null) !== null && $filters['category'] !== '') {
            $query->whereHas('category', fn ($category) => $category->where('slug', (string) $filters['category']));
        }

        $query = match ($filters['sort'] ?? null) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };

        return $query->paginate(min((int) ($filters['per_page'] ?? 24), 60))->withQueryString();
    }

    public function product(Store $store, string $slug): Product
    {
        return Product::query()
            ->active()
            ->where('slug', $slug)
            ->where(fn ($inner) => $this->availableProductQuery($inner, $store))
            ->with([
                'category',
                'images',
                'variants.inventories' => fn ($inventory) => $inventory->where('store_id', $store->getKey()),
                'inventories' => fn ($inventory) => $inventory->where('store_id', $store->getKey()),
            ])
            ->firstOrFail();
    }

    /**
     * A product is stocked by a store when it is shared across the tenant
     * (`store_id` NULL) or assigned to this store explicitly.
     */
    private function availableProductQuery(mixed $query, Store $store): mixed
    {
        return $query
            ->where('tenant_id', $store->tenant_id)
            ->where(fn ($inner) => $inner->whereNull('store_id')->orWhere('store_id', $store->getKey()));
    }
}
