<?php

declare(strict_types=1);

namespace App\Services\Bundles;

use App\Enums\BundleStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Catalog\CatalogFingerprint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Smart Bundle recommendations.
 *
 * Three sources are deliberately kept apart:
 *
 *  - merchant-curated bundles that already contain the anchor product;
 *  - measured co-purchases from real order items;
 *  - a rule-based catalogue match in the same category.
 *
 * The evidence block says which one was used. A rule-based match is never
 * dressed up as demand, and an order count is never converted into a
 * "conversion rate" the data cannot support.
 */
class BundleRecommendationService
{
    private const WINDOW_DAYS = 180;

    private const CANDIDATE_LIMIT = 20;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CatalogFingerprint $fingerprints,
        private readonly BundleService $bundles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recommend(Product $anchor, ?int $storeId = null, int $limit = 5, ?string $expectedFingerprint = null): array
    {
        $tenant = $this->tenants->required();
        $anchor->loadMissing(['category', 'variants.inventories']);

        $fingerprint = $this->fingerprints->forTenant((int) $tenant->getKey());

        if ($expectedFingerprint !== null && ! hash_equals($fingerprint, $expectedFingerprint)) {
            throw BusinessRuleViolation::because(
                'Recommendations changed since you last looked at them. Refresh before adding a bundle.',
                ['catalog_fingerprint' => $fingerprint],
                'stale_recommendations',
            );
        }

        $store = $this->resolveStore($storeId);
        $limit = max(1, min($limit, 10));
        $recommendations = [];
        $usedProducts = [(int) $anchor->getKey() => true];

        if (! $store instanceof Store) {
            return $this->payload($anchor, $store, $recommendations, $fingerprint);
        }

        $anchorVariant = $this->variantFor($anchor, $store);

        // A recommendation is only useful when the anchor itself can be sold in
        // this store. Otherwise every suggestion would start with an unavailable
        // line and fail in the Smart Cart.
        if (! $anchorVariant instanceof ProductVariant || $this->available($anchorVariant, $store) < 1) {
            return $this->payload($anchor, $store, [], $fingerprint);
        }

        foreach ($this->curatedBundles($anchor, $store) as $bundle) {
            $preview = $this->bundles->preview($bundle, (int) $store->getKey());

            foreach ($preview['items'] as $line) {
                $usedProducts[(int) $line['product_id']] = true;
            }

            $recommendations[] = [
                'key' => 'bundle:'.$bundle->getKey(),
                'kind' => 'bundle',
                'source' => 'merchant_curated',
                'title' => $bundle->name,
                'description' => $bundle->description,
                'bundle' => [
                    'id' => (int) $bundle->getKey(),
                    'name' => $bundle->name,
                    'description' => $bundle->description,
                    'status' => $bundle->status->value,
                    'discount_type' => $bundle->discount_type->value,
                    'discount_value' => (float) $bundle->discount_value,
                    'currency' => $bundle->currency,
                ],
                'items' => $preview['items'],
                'subtotal' => $preview['subtotal'],
                'discount' => $preview['discount'],
                'total' => $preview['total'],
                'currency' => $preview['currency'],
                'is_available' => $preview['is_available'],
                'issues' => $preview['issues'],
                'evidence' => [
                    'basis' => 'merchant_curated',
                    'confidence' => 'curated',
                    'order_count' => null,
                    'unit_count' => null,
                    'last_observed_at' => null,
                    'window_days' => null,
                ],
                'explanation' => 'This is a bundle your workspace already prepared.',
                'catalog_fingerprint' => $fingerprint,
            ];

            if (count($recommendations) >= $limit) {
                return $this->payload($anchor, $store, $recommendations, $fingerprint);
            }
        }

        foreach ($this->measuredCandidates($anchor) as $candidate) {
            if (count($recommendations) >= $limit) {
                break;
            }

            $partner = Product::query()->with(['category', 'variants.inventories'])->find($candidate->product_id);

            if (! $this->isCompatible($anchor, $partner, $store) || isset($usedProducts[(int) $partner->getKey()])) {
                continue;
            }

            $partnerVariant = $this->variantFor($partner, $store);

            if (! $partnerVariant instanceof ProductVariant || $this->available($partnerVariant, $store) < 1) {
                continue;
            }

            $recommendations[] = $this->suggestion($anchor, $anchorVariant, $partner, $partnerVariant, $store, [
                'basis' => 'purchased_together',
                'confidence' => 'measured',
                'order_count' => (int) $candidate->order_count,
                'unit_count' => (int) $candidate->unit_count,
                'last_observed_at' => $candidate->last_observed_at,
                'window_days' => self::WINDOW_DAYS,
            ], $fingerprint);

            $usedProducts[(int) $partner->getKey()] = true;
        }

        if (count($recommendations) < $limit) {
            foreach ($this->categoryCandidates($anchor, $store) as $partner) {
                if (count($recommendations) >= $limit) {
                    break;
                }

                if (! $this->isCompatible($anchor, $partner, $store) || isset($usedProducts[(int) $partner->getKey()])) {
                    continue;
                }

                $partnerVariant = $this->variantFor($partner, $store);

                if (! $partnerVariant instanceof ProductVariant || $this->available($partnerVariant, $store) < 1) {
                    continue;
                }

                $recommendations[] = $this->suggestion($anchor, $anchorVariant, $partner, $partnerVariant, $store, [
                    'basis' => 'same_category',
                    'confidence' => 'rule_based',
                    'order_count' => null,
                    'unit_count' => null,
                    'last_observed_at' => null,
                    'window_days' => null,
                ], $fingerprint);

                $usedProducts[(int) $partner->getKey()] = true;
            }
        }

        return $this->payload($anchor, $store, array_slice($recommendations, 0, $limit), $fingerprint);
    }

    /**
     * Evidence for one anchor/partner pair, recomputed on the server.
     *
     * The AI explanation endpoint calls this rather than trusting counts sent
     * by the browser, so a crafted request cannot make the model cite demand
     * that never happened.
     *
     * @return array<string, mixed>
     */
    public function evidenceFor(Product $anchor, Product $partner, ?int $storeId = null): array
    {
        $this->tenants->required();
        $anchor->loadMissing(['category', 'variants.inventories']);
        $partner->loadMissing(['category', 'variants.inventories']);
        $store = $this->resolveStore($storeId);

        if (! $store instanceof Store || ! $this->isCompatible($anchor, $partner, $store)) {
            throw BusinessRuleViolation::because(
                'These products cannot be recommended together for the selected store.',
                ['partner_product_id' => (int) $partner->getKey()],
                'incompatible_bundle_products',
            );
        }

        $row = $this->measuredCandidates($anchor)
            ->firstWhere('product_id', $partner->getKey());

        if ($row !== null) {
            return [
                'basis' => 'purchased_together',
                'confidence' => 'measured',
                'order_count' => (int) $row->order_count,
                'unit_count' => (int) $row->unit_count,
                'last_observed_at' => $row->last_observed_at,
                'window_days' => self::WINDOW_DAYS,
                'category' => $anchor->category?->name,
            ];
        }

        if ($anchor->category_id !== null && (int) $anchor->category_id === (int) $partner->category_id) {
            return [
                'basis' => 'same_category',
                'confidence' => 'rule_based',
                'order_count' => null,
                'unit_count' => null,
                'last_observed_at' => null,
                'window_days' => null,
                'category' => $anchor->category?->name,
            ];
        }

        throw BusinessRuleViolation::because(
            'There is no measured or catalogue evidence for this pair.',
            ['partner_product_id' => (int) $partner->getKey()],
            'no_bundle_evidence',
        );
    }

    /**
     * @return Collection<int, Bundle>
     */
    private function curatedBundles(Product $anchor, Store $store): Collection
    {
        return Bundle::query()
            ->where('status', BundleStatus::Active)
            ->where(fn ($query) => $query
                ->whereNull('store_id')
                ->orWhere('store_id', $store->getKey()))
            ->whereHas('items', fn ($query) => $query->where('product_id', $anchor->getKey()))
            ->with($this->bundles->relations())
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
    }

    /**
     * Real order history: how often the partner appeared in an order that also
     * contained the anchor. Cancelled orders are excluded because they never
     * represent a completed complement.
     *
     * @return Collection<int, object>
     */
    private function measuredCandidates(Product $anchor): Collection
    {
        $tenantId = (int) $anchor->tenant_id;

        return DB::table('order_items as anchor_items')
            ->join('orders', 'orders.id', '=', 'anchor_items.order_id')
            ->join('order_items as partner_items', function ($join): void {
                $join->on('partner_items.order_id', '=', 'anchor_items.order_id')
                    ->whereColumn('partner_items.product_id', '!=', 'anchor_items.product_id');
            })
            ->where('anchor_items.tenant_id', $tenantId)
            ->where('anchor_items.product_id', $anchor->getKey())
            ->where('orders.tenant_id', $tenantId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.placed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotNull('partner_items.product_id')
            ->groupBy('partner_items.product_id')
            ->selectRaw('partner_items.product_id, COUNT(DISTINCT anchor_items.order_id) AS order_count, SUM(partner_items.quantity) AS unit_count, MAX(orders.placed_at) AS last_observed_at')
            ->orderByDesc('order_count')
            ->orderByDesc('unit_count')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    private function categoryCandidates(Product $anchor, Store $store): Collection
    {
        if ($anchor->category_id === null) {
            return collect();
        }

        return Product::query()
            ->active()
            ->whereKeyNot($anchor->getKey())
            ->where('category_id', $anchor->category_id)
            ->where(fn ($query) => $query
                ->whereNull('store_id')
                ->orWhere('store_id', $store->getKey()))
            ->with(['category', 'variants.inventories'])
            ->orderBy('name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    private function isCompatible(?Product $anchor, ?Product $partner, Store $store): bool
    {
        if (! $anchor instanceof Product || ! $partner instanceof Product) {
            return false;
        }

        if (! $partner->isSellable() || (int) $partner->getKey() === (int) $anchor->getKey()) {
            return false;
        }

        if ($partner->store_id !== null && (int) $partner->store_id !== (int) $store->getKey()) {
            return false;
        }

        if (strtoupper((string) $partner->currency) !== strtoupper((string) $store->currency)) {
            return false;
        }

        return strtoupper((string) $anchor->currency) === strtoupper((string) $store->currency);
    }

    private function variantFor(Product $product, Store $store): ?ProductVariant
    {
        $variants = $product->variants;
        $default = $variants->firstWhere('is_default', true);

        $ordered = $default instanceof ProductVariant
            ? collect([$default])->merge($variants->where('is_default', false))
            : $variants;

        foreach ($ordered as $variant) {
            $inventory = $variant->inventories->firstWhere('store_id', $store->getKey());

            if ($inventory !== null && $inventory->available() > 0) {
                return $variant;
            }
        }

        return $default instanceof ProductVariant ? $default : $variants->first();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function suggestion(
        Product $anchor,
        ProductVariant $anchorVariant,
        Product $partner,
        ProductVariant $partnerVariant,
        Store $store,
        array $evidence,
        string $fingerprint,
    ): array {
        $anchorAvailable = $this->available($anchorVariant, $store);
        $partnerAvailable = $this->available($partnerVariant, $store);

        $items = [
            $this->line($anchor, $anchorVariant, $anchorAvailable),
            $this->line($partner, $partnerVariant, $partnerAvailable),
        ];

        $subtotal = round(array_sum(array_column($items, 'line_subtotal')), 2);
        $isAvailable = $anchorAvailable >= 1 && $partnerAvailable >= 1;

        return [
            'key' => 'suggested:'.$anchor->getKey().':'.$partner->getKey(),
            'kind' => 'suggested',
            'source' => 'generated',
            'title' => $anchor->name.' + '.$partner->name,
            'description' => null,
            'bundle' => null,
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => 0.0,
            'total' => $subtotal,
            'currency' => strtoupper((string) $store->currency),
            'is_available' => $isAvailable,
            'issues' => $isAvailable ? [] : [[
                'code' => 'insufficient_stock',
                'message' => 'One of these products does not have enough stock in this store.',
                'product_id' => $anchorAvailable < 1 ? $anchor->getKey() : $partner->getKey(),
                'product_variant_id' => $anchorAvailable < 1 ? $anchorVariant?->getKey() : $partnerVariant->getKey(),
            ]],
            'evidence' => $evidence,
            'explanation' => $this->explain($anchor, $partner, $evidence),
            'catalog_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Product $product, ProductVariant $variant, int $available): array
    {
        $unitPrice = (float) $variant->price;

        return [
            'id' => null,
            'product_id' => (int) $product->getKey(),
            'product_variant_id' => (int) $variant->getKey(),
            'name' => $product->name.($variant->is_default ? '' : ' — '.$variant->name),
            'sku' => $variant->sku ?? $product->sku,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'line_subtotal' => $unitPrice,
            'available' => $available,
            'is_available' => $available >= 1,
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

    private function available(ProductVariant $variant, Store $store): int
    {
        $inventory = $variant->inventories->firstWhere('store_id', $store->getKey());

        return $inventory === null ? 0 : $inventory->available();
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function explain(Product $anchor, Product $partner, array $evidence): string
    {
        return match ($evidence['basis']) {
            'purchased_together' => sprintf(
                '%s appeared with %s in %d measured order(s) over the last %d days (%d units). This is order history, not a conversion rate.',
                $partner->name,
                $anchor->name,
                (int) $evidence['order_count'],
                self::WINDOW_DAYS,
                (int) $evidence['unit_count'],
            ),
            'same_category' => sprintf(
                '%s is another active product in the same category as %s. This is a catalogue match, not measured demand.',
                $partner->name,
                $anchor->name,
            ),
            default => 'This is a bundle your workspace prepared.',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return array<string, mixed>
     */
    private function payload(Product $anchor, ?Store $store, array $recommendations, string $fingerprint): array
    {
        return [
            'anchor' => [
                'product_id' => (int) $anchor->getKey(),
                'name' => $anchor->name,
                'slug' => $anchor->slug,
                'currency' => strtoupper((string) $anchor->currency),
                'category' => $anchor->category?->name,
            ],
            'store' => $store instanceof Store ? [
                'id' => (int) $store->getKey(),
                'name' => $store->name,
                'currency' => strtoupper((string) $store->currency),
            ] : null,
            'recommendations' => $recommendations,
            'catalog_fingerprint' => $fingerprint,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function resolveStore(?int $storeId): ?Store
    {
        if ($storeId !== null) {
            return Store::query()->find($storeId);
        }

        return Store::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
