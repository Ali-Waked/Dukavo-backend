<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * A cheap, deterministic answer to "has anything that could change a bundle
 * recommendation changed?".
 *
 * The fingerprint covers catalogue rows and stock positions, not just bundle
 * rows. A price edit, a stock movement or a deactivated product all invalidate
 * a recommendation that was rendered a moment ago.
 */
final class CatalogFingerprint
{
    public function forTenant(int $tenantId): string
    {
        $products = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS row_count, MAX(updated_at) AS touched_at, SUM(price) AS price_total')
            ->first();

        $variants = DB::table('product_variants')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS row_count, MAX(updated_at) AS touched_at, SUM(price) AS price_total')
            ->first();

        $inventories = DB::table('inventories')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS row_count, MAX(updated_at) AS touched_at, SUM(quantity) AS quantity_total, SUM(reserved_quantity) AS reserved_total')
            ->first();

        $bundles = DB::table('bundles')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS row_count, MAX(updated_at) AS touched_at, SUM(discount_value) AS discount_total')
            ->first();

        // Bundle lines live in their own table. Updating only the lines does not
        // touch the bundle row, so omitting them here would let a preview
        // survive a line swap that changed the actual offer.
        $bundleItems = DB::table('bundle_items')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS row_count, MAX(updated_at) AS touched_at, SUM(quantity) AS quantity_total, SUM(product_id) AS product_total, SUM(product_variant_id) AS variant_total')
            ->first();

        return hash('sha256', json_encode([
            'tenant' => $tenantId,
            'products' => (array) $products,
            'variants' => (array) $variants,
            'inventories' => (array) $inventories,
            'bundles' => (array) $bundles,
            'bundle_items' => (array) $bundleItems,
        ], JSON_THROW_ON_ERROR));
    }
}
