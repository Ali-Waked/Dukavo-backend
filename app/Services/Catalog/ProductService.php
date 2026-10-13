<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Product and variant lifecycle.
 *
 * Invariant maintained here: every product has at least one variant and
 * exactly one default variant. Inventory, orders and channel adapters all
 * require a concrete variant to work with, so a product without one would be
 * unsellable in a way that is very hard to debug from the UI.
 */
class ProductService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        $tenant = $this->tenants->required();

        try {
            return DB::transaction(function () use ($tenant, $attributes): Product {
                $category = $this->resolveCategory($attributes['category_id'] ?? null);
                $store = $this->resolveStore($attributes['store_id'] ?? null);

                // A slug the merchant chose is honoured exactly; only when they
                // leave it blank does the platform derive one from the name.
                // Silently rewriting a supplied slug would break the URL the
                // merchant just copied from the form.
                $slug = $attributes['slug'] ?? null;
                if ($slug === null || $slug === '') {
                    $slug = $this->uniqueSlug($attributes['name']);
                }

                $product = Product::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'store_id' => $store?->getKey(),
                    'category_id' => $category?->getKey(),
                    'name' => $attributes['name'],
                    'slug' => $slug,
                    'sku' => $attributes['sku'] ?? null,
                    'description' => $attributes['description'] ?? null,
                    'status' => $attributes['status'] ?? ProductStatus::Draft,
                    'price' => $attributes['price'] ?? 0,
                    'cost' => $attributes['cost'] ?? 0,
                    'currency' => $attributes['currency'] ?? $tenant->currency,
                    'is_taxable' => $attributes['is_taxable'] ?? true,
                ]);

                $this->syncVariants($product, $attributes['variants'] ?? []);

                return $product->load(['variants', 'images', 'category']);
            });
        } catch (UniqueConstraintViolationException) {
            // The validation rule and the database index race under concurrent
            // saves; this turns the loser into the same 422 the form already
            // knows how to display rather than a 500.
            throw ValidationException::withMessages([
                'slug' => 'This slug is already in use. Please choose another one.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        try {
            return DB::transaction(function () use ($product, $attributes): Product {
                if (array_key_exists('category_id', $attributes)) {
                    $attributes['category_id'] = $this->resolveCategory($attributes['category_id'])?->getKey();
                }

                if (array_key_exists('store_id', $attributes)) {
                    $attributes['store_id'] = $this->resolveStore($attributes['store_id'])?->getKey();
                }

                // An empty slug means "keep the current one"; clearing it and
                // changing the name at the same time regenerates a fresh slug.
                if (array_key_exists('slug', $attributes) && ($attributes['slug'] === null || $attributes['slug'] === '')) {
                    unset($attributes['slug']);
                }

                if (array_key_exists('name', $attributes) && ! array_key_exists('slug', $attributes)) {
                    $attributes['slug'] = $this->uniqueSlug($attributes['name'], $product->getKey());
                }

                if (array_key_exists('variants', $attributes)) {
                    $this->syncVariants($product, $attributes['variants']);
                    unset($attributes['variants']);
                }

                $product->fill($attributes)->save();

                return $product->load(['variants', 'images', 'category']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is already in use. Please choose another one.',
            ]);
        }
    }

    /**
     * Soft delete by default: products that have been ordered must not vanish
     * from order history. A hard delete is only offered when there is nothing
     * referencing the product.
     */
    public function delete(Product $product, bool $force = false): void
    {
        $isReferenced = $product->orderItems()->exists();

        if ($isReferenced) {
            // History wins over tidiness: a product that appears on an order is
            // archived, never removed, and never forced out from under that order.
            if ($force) {
                throw BusinessRuleViolation::because('This product is referenced by existing orders and cannot be deleted.');
            }

            $product->forceFill(['status' => ProductStatus::Archived])->save();

            return;
        }

        if (! $force) {
            $product->delete();

            return;
        }

        DB::transaction(function () use ($product): void {
            $product->images()->delete();
            $product->inventories()->delete();
            $product->variants()->delete();
            $product->forceDelete();
        });
    }

    /**
     * Replace the variant set. Variants referenced by an order item are
     * updated rather than removed, because order history must stay intact.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        if ($variants === []) {
            $variants = [[
                'name' => 'Default',
                'sku' => $product->sku,
                'price' => $product->price,
                'cost' => $product->cost,
                'is_default' => true,
            ]];
        }

        $existing = $product->variants()->get()->keyBy('id');
        $keepIds = [];
        $position = 0;

        foreach ($variants as $variant) {
            $position++;
            $id = $variant['id'] ?? null;

            $payload = [
                'name' => $variant['name'],
                'sku' => $variant['sku'] ?? null,
                'barcode' => $variant['barcode'] ?? null,
                'price' => $variant['price'],
                'cost' => $variant['cost'] ?? $product->cost,
                'is_default' => (bool) ($variant['is_default'] ?? false),
                'position' => $position,
            ];

            if ($id !== null && $existing->has((int) $id)) {
                $existing->get((int) $id)?->update($payload);
                $keepIds[] = (int) $id;

                continue;
            }

            $created = $product->variants()->create($payload);
            $keepIds[] = $created->getKey();
        }

        // Only variants nobody has ordered may be dropped.
        $product->variants()
            ->whereNotIn('id', $keepIds)
            ->whereDoesntHave('orderItems')
            ->delete();

        $this->ensureSingleDefault($product->fresh());
    }

    private function ensureSingleDefault(Product $product): void
    {
        $variants = $product->variants()->orderBy('position')->get();

        if ($variants->isEmpty()) {
            return;
        }

        $default = $variants->firstWhere('is_default', true) ?? $variants->first();

        ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->update(['is_default' => false]);

        $default->forceFill(['is_default' => true])->save();
    }

    /**
     * Validate that a category belongs to the active tenant. A null id is
     * allowed (uncategorised products are legitimate).
     */
    private function resolveCategory(mixed $categoryId): ?Category
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        // Category is tenant-scoped, so a foreign tenant id simply is not found.
        return Category::query()->find($categoryId)
            ?? throw BusinessRuleViolation::because('The selected category does not exist.');
    }

    private function resolveStore(mixed $storeId): ?Store
    {
        if ($storeId === null || $storeId === '') {
            return null;
        }

        return Store::query()->find($storeId)
            ?? throw BusinessRuleViolation::because('The selected store does not exist.');
    }

    /**
     * SKUs already taken inside the active tenant, used by the catalogue UI to
     * warn before a duplicate is saved.
     *
     * @return Collection<int, string>
     */
    public function skusInUse(): Collection
    {
        return ProductVariant::query()->whereNotNull('sku')->pluck('sku');
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $tenant = $this->tenants->required();

        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (Product::query()
            ->withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
