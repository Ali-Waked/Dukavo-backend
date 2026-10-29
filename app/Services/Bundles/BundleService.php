<?php

declare(strict_types=1);

namespace App\Services\Bundles;

use App\Enums\BundleDiscountType;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Catalog\CatalogFingerprint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Bundle lifecycle and pricing.
 *
 * As with carts and orders, money is never accepted from the browser. The
 * service reads current variant prices, applies the merchant's discount rule,
 * caps it at the subtotal and reports per-line availability from the selected
 * store's inventory. A bundle is only "available" when every line can actually
 * be fulfilled.
 */
class BundleService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CatalogFingerprint $fingerprints,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Bundle
    {
        return DB::transaction(function () use ($attributes): Bundle {
            $store = $this->resolveOptionalStore($attributes['store_id'] ?? null);
            $currency = strtoupper((string) ($attributes['currency'] ?? $store?->currency ?? $this->defaultStore()?->currency ?? $this->tenants->required()->currency));

            $bundle = Bundle::query()->create([
                'store_id' => $store?->getKey(),
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'status' => $attributes['status'] ?? 'draft',
                'discount_type' => $attributes['discount_type'] ?? BundleDiscountType::Percentage,
                'discount_value' => $attributes['discount_value'] ?? 0,
                'currency' => $currency,
            ]);

            $this->syncItems($bundle, $attributes['items'] ?? []);
            $this->assertDiscountIsValid($bundle);

            return $bundle->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Bundle $bundle, array $attributes): Bundle
    {
        return DB::transaction(function () use ($bundle, $attributes): Bundle {
            if (array_key_exists('store_id', $attributes)) {
                $attributes['store_id'] = $this->resolveOptionalStore($attributes['store_id'])?->getKey();
            }

            if (array_key_exists('currency', $attributes)) {
                $attributes['currency'] = strtoupper((string) $attributes['currency']);
            }

            $items = $attributes['items'] ?? null;
            unset($attributes['items']);

            $bundle->fill($attributes)->save();

            if (is_array($items)) {
                $this->syncItems($bundle, $items);
            } else {
                $this->assertExistingItemsAreCompatible($bundle);
            }

            $this->assertDiscountIsValid($bundle);

            return $bundle->load($this->relations());
        });
    }

    /**
     * Soft delete. Bundle references are not financial records, so archiving a
     * bundle never rewrites history elsewhere.
     */
    public function delete(Bundle $bundle): void
    {
        $bundle->delete();
    }

    /**
     * Price and availability for one store.
     *
     * @return array<string, mixed>
     */
    public function preview(Bundle $bundle, ?int $storeId = null, ?string $expectedFingerprint = null): array
    {
        $bundle->loadMissing($this->relations());
        $tenantId = (int) $bundle->tenant_id;
        $fingerprint = $this->fingerprints->forTenant($tenantId);

        if ($expectedFingerprint !== null && ! hash_equals($fingerprint, $expectedFingerprint)) {
            throw BusinessRuleViolation::because(
                'This bundle changed since you last looked at it. Refresh the preview before continuing.',
                ['catalog_fingerprint' => $fingerprint],
                'stale_bundle_preview',
            );
        }

        $store = $this->resolveStore($storeId, $bundle);

        $subtotal = 0.0;
        $lines = [];
        $issues = [];

        foreach ($bundle->items as $item) {
            /** @var BundleItem $item */
            $product = $item->product;
            $variant = $item->variant;
            $quantity = max(1, (int) $item->quantity);

            if (! $product instanceof Product || ! $variant instanceof ProductVariant) {
                $issues[] = $this->issue('product_missing', 'A product in this bundle is no longer available.', $item);

                continue;
            }

            $unitPrice = (float) $variant->price;
            $lineSubtotal = round($unitPrice * $quantity, 2);
            $available = $store instanceof Store
                ? $this->availableInStore($store, $variant)
                : 0;

            $lineIssue = $this->lineIssue($bundle, $store, $product, $variant, $available, $quantity);

            if ($lineIssue !== null) {
                $issues[] = $lineIssue;
            }

            $subtotal += $lineSubtotal;

            $lines[] = [
                'id' => (int) $item->getKey(),
                'product_id' => (int) $product->getKey(),
                'product_variant_id' => (int) $variant->getKey(),
                'name' => $this->lineName($product, $variant),
                'sku' => $variant->sku ?? $product->sku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'available' => $available,
                'is_available' => $lineIssue === null,
                'product' => [
                    'id' => (int) $product->getKey(),
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'status' => $product->status->value,
                    'currency' => strtoupper((string) $product->currency),
                ],
                'variant' => [
                    'id' => (int) $variant->getKey(),
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $unitPrice,
                    'is_default' => (bool) $variant->is_default,
                ],
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = $this->discountFor($bundle, $subtotal);
        $total = round(max(0.0, $subtotal - $discount), 2);
        $isAvailable = $store instanceof Store
            && $bundle->isSellable()
            && $lines !== []
            && $issues === [];

        return [
            'bundle_id' => (int) $bundle->getKey(),
            'status' => $bundle->status->value,
            'store' => $store instanceof Store ? [
                'id' => (int) $store->getKey(),
                'name' => $store->name,
                'currency' => strtoupper((string) $store->currency),
            ] : null,
            'currency' => strtoupper((string) $bundle->currency),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'item_count' => count($lines),
            'is_available' => $isAvailable,
            'issues' => $issues,
            'items' => $lines,
            'catalog_fingerprint' => $fingerprint,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function discountFor(Bundle $bundle, float $subtotal): float
    {
        $value = max(0.0, (float) $bundle->discount_value);

        $discount = match ($bundle->discount_type) {
            BundleDiscountType::Percentage => $subtotal * min($value, 100.0) / 100.0,
            BundleDiscountType::Fixed => min($value, $subtotal),
        };

        return round(min($discount, $subtotal), 2);
    }

    /**
     * @return array<int, string>
     */
    public function relations(): array
    {
        return [
            'store',
            'items.product.category',
            'items.variant.inventories',
        ];
    }

    /**
     * Replace a bundle's lines. The unique index on (bundle, variant) backs the
     * duplicate check below; the check exists so a duplicate returns a readable
     * 409 rather than a database error.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(Bundle $bundle, array $items): void
    {
        if ($items === []) {
            throw BusinessRuleViolation::because(
                'A bundle needs at least two products.',
                [],
                'bundle_needs_items',
            );
        }

        $bundle->loadMissing(['store']);
        $currency = strtoupper((string) $bundle->currency);
        $seen = [];
        $position = 0;

        $rows = [];

        foreach ($items as $item) {
            $position++;
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity < 1) {
                throw BusinessRuleViolation::because(
                    'Each bundle line must have a quantity of at least 1.',
                    ['product_variant_id' => $item['product_variant_id'] ?? null],
                    'invalid_bundle_quantity',
                );
            }

            $product = Product::query()->findOrFail($item['product_id'] ?? null);
            $variant = ProductVariant::query()
                ->where('product_id', $product->getKey())
                ->find($item['product_variant_id'] ?? null);

            if (! $variant instanceof ProductVariant) {
                throw BusinessRuleViolation::because(
                    'The selected variant does not belong to the selected product.',
                    [
                        'product_id' => (int) $product->getKey(),
                        'product_variant_id' => $item['product_variant_id'] ?? null,
                    ],
                    'invalid_bundle_variant',
                );
            }

            if (isset($seen[(int) $variant->getKey()])) {
                throw BusinessRuleViolation::because(
                    'A variant can only appear once in a bundle.',
                    ['product_variant_id' => (int) $variant->getKey()],
                    'duplicate_bundle_variant',
                );
            }

            $seen[(int) $variant->getKey()] = true;

            if (strtoupper((string) $product->currency) !== $currency) {
                throw BusinessRuleViolation::because(
                    'Every product in a bundle must use the bundle currency.',
                    [
                        'product_id' => (int) $product->getKey(),
                        'product_currency' => strtoupper((string) $product->currency),
                        'bundle_currency' => $currency,
                    ],
                    'bundle_currency_mismatch',
                );
            }

            if ($bundle->store_id !== null && $product->store_id !== null && (int) $product->store_id !== (int) $bundle->store_id) {
                throw BusinessRuleViolation::because(
                    'A product assigned to another store cannot be part of this bundle.',
                    ['product_id' => (int) $product->getKey()],
                    'bundle_product_store_mismatch',
                );
            }

            $rows[] = [
                'tenant_id' => (int) $bundle->tenant_id,
                'bundle_id' => (int) $bundle->getKey(),
                'product_id' => (int) $product->getKey(),
                'product_variant_id' => (int) $variant->getKey(),
                'quantity' => $quantity,
                'position' => $position,
            ];
        }

        if (count($rows) < 2) {
            throw BusinessRuleViolation::because(
                'A bundle needs at least two different products.',
                [],
                'bundle_needs_items',
            );
        }

        BundleItem::query()->where('bundle_id', $bundle->getKey())->delete();

        foreach ($rows as $row) {
            BundleItem::query()->create($row);
        }

        $bundle->unsetRelation('items');
    }

    /**
     * Re-check the lines already in the bundle when the merchant changes the
     * bundle's store or currency without replacing the lines.
     */
    private function assertExistingItemsAreCompatible(Bundle $bundle): void
    {
        $bundle->loadMissing(['items.product']);

        foreach ($bundle->items as $item) {
            /** @var BundleItem $item */
            $product = $item->product;

            if (! $product instanceof Product) {
                throw BusinessRuleViolation::because(
                    'A product in this bundle is no longer available.',
                    ['bundle_item_id' => (int) $item->getKey()],
                    'bundle_product_missing',
                );
            }

            if (strtoupper((string) $product->currency) !== strtoupper((string) $bundle->currency)) {
                throw BusinessRuleViolation::because(
                    'Every product in a bundle must use the bundle currency.',
                    ['product_id' => (int) $product->getKey()],
                    'bundle_currency_mismatch',
                );
            }

            if ($bundle->store_id !== null && $product->store_id !== null && (int) $product->store_id !== (int) $bundle->store_id) {
                throw BusinessRuleViolation::because(
                    'A product assigned to another store cannot be part of this bundle.',
                    ['product_id' => (int) $product->getKey()],
                    'bundle_product_store_mismatch',
                );
            }
        }
    }

    private function assertDiscountIsValid(Bundle $bundle): void
    {
        $value = (float) $bundle->discount_value;

        if ($value < 0 || ! is_finite($value)) {
            throw BusinessRuleViolation::because(
                'A bundle discount cannot be negative.',
                ['discount_value' => $value],
                'invalid_bundle_discount',
            );
        }

        if ($bundle->discount_type === BundleDiscountType::Percentage && $value > 100) {
            throw BusinessRuleViolation::because(
                'A percentage discount cannot exceed 100%.',
                ['discount_value' => $value],
                'invalid_bundle_discount',
            );
        }
    }

    /**
     * A store the caller explicitly named, or null when the bundle is meant to
     * be available in every store. Never silently promotes "all stores" to the
     * default store: that would narrow a merchant's bundle without telling them.
     */
    private function resolveOptionalStore(mixed $storeId): ?Store
    {
        if ($storeId === null || $storeId === '') {
            return null;
        }

        return Store::query()->findOrFail($storeId);
    }

    /**
     * The store used to answer "what does this cost and is it in stock?".
     * An explicit request wins, then the bundle's own store, then the default.
     */
    private function resolveStore(mixed $storeId, ?Bundle $bundle = null): ?Store
    {
        if ($storeId !== null && $storeId !== '') {
            return Store::query()->findOrFail($storeId);
        }

        if ($bundle?->store_id !== null) {
            return Store::query()->find($bundle->store_id);
        }

        return $this->defaultStore();
    }

    private function defaultStore(): ?Store
    {
        return Store::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function availableInStore(Store $store, ProductVariant $variant): int
    {
        $inventory = $variant->inventories->firstWhere('store_id', $store->getKey());

        return $inventory === null ? 0 : $inventory->available();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lineIssue(
        Bundle $bundle,
        ?Store $store,
        Product $product,
        ProductVariant $variant,
        int $available,
        int $quantity,
    ): ?array {
        if (! $store instanceof Store) {
            return $this->issue('store_missing', 'No store is available to check this bundle.', null);
        }

        if (! $product->isSellable()) {
            return $this->issue('product_inactive', 'This product is not active.', null, $product);
        }

        if (strtoupper((string) $product->currency) !== strtoupper((string) $bundle->currency)) {
            return $this->issue('bundle_currency_mismatch', 'This product uses a different currency from the bundle.', null, $product);
        }

        if ($product->store_id !== null && (int) $product->store_id !== (int) $store->getKey()) {
            return $this->issue('product_not_in_store', 'This product is not sold in the selected store.', null, $product);
        }

        if (strtoupper((string) $bundle->currency) !== strtoupper((string) $store->currency)) {
            return $this->issue('store_currency_mismatch', 'This bundle uses a different currency from the store.', null, $product);
        }

        if ($available < $quantity) {
            return $this->issue(
                'insufficient_stock',
                sprintf('Only %d units of %s are available.', $available, $this->lineName($product, $variant)),
                null,
                $product,
                $variant,
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $code, string $message, ?BundleItem $item, ?Product $product = null, ?ProductVariant $variant = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'bundle_item_id' => $item?->getKey(),
            'product_id' => $product?->getKey() ?? $item?->product_id,
            'product_variant_id' => $variant?->getKey() ?? $item?->product_variant_id,
        ];
    }

    private function lineName(Product $product, ProductVariant $variant): string
    {
        return $product->name.($variant->is_default ? '' : ' — '.$variant->name);
    }
}
