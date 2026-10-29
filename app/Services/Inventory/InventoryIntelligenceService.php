<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns the stock ledger into decisions a merchant can defend.
 *
 * The service only uses recorded movements and current stock. It never invents
 * demand for a product without enough history: below the configured minimum it
 * returns `insufficient_history` and a null daily rate. Every suggested order is
 * arithmetic over a stated lead time, safety buffer and review period, not a
 * prediction pretending to be certainty.
 */
class InventoryIntelligenceService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(
        ?Store $store,
        Carbon $from,
        Carbon $until,
        string $status = 'all',
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $inventories = $this->inventoryQuery($store, $search)->get();
        $metrics = $this->metrics($inventories, $from, $until);
        $filtered = array_values(array_filter(
            $metrics,
            fn (array $item): bool => $this->matchesStatus($item, $status),
        ));

        usort($filtered, function (array $left, array $right): int {
            $weight = $this->sortWeight($left) <=> $this->sortWeight($right);

            return $weight !== 0 ? $weight : strcmp($left['product_name'], $right['product_name']);
        });

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return [
            'range' => $this->rangePayload($from, $until),
            'summary' => $this->summary($metrics),
            'stores' => $this->storeBreakdown($metrics),
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $items === [] ? null : (($page - 1) * $perPage) + 1,
                'to' => $items === [] ? null : (($page - 1) * $perPage) + count($items),
            ],
            'settings' => $this->settingsPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Inventory $inventory, Carbon $from, Carbon $until): array
    {
        $inventory->load(['product', 'variant', 'store']);

        $items = $this->metrics(new Collection([$inventory]), $from, $until);
        $item = $items[0];

        return [
            'range' => $this->rangePayload($from, $until),
            'item' => $item + ['series' => $this->series($inventory, $from, $until)],
            'settings' => $this->settingsPayload(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportRows(
        ?Store $store,
        Carbon $from,
        Carbon $until,
        string $status = 'all',
        ?string $search = null,
    ): array {
        $metrics = $this->metrics($this->inventoryQuery($store, $search)->get(), $from, $until);

        return array_values(array_filter(
            $metrics,
            fn (array $item): bool => $this->matchesStatus($item, $status),
        ));
    }

    /**
     * @return Builder<Inventory>
     */
    private function inventoryQuery(?Store $store, ?string $search): Builder
    {
        return Inventory::query()
            ->with([
                'product:id,name,sku,cost,currency',
                'variant:id,product_id,name,sku,cost',
                'store:id,name,currency,timezone',
            ])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->whereHas('product', fn (Builder $product) => $product
                            ->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like))
                        ->orWhereHas('variant', fn (Builder $variant) => $variant
                            ->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like));
                });
            });
    }

    /**
     * @param  Collection<int, Inventory>  $inventories
     * @return list<array<string, mixed>>
     */
    private function metrics(Collection $inventories, Carbon $from, Carbon $until): array
    {
        if ($inventories->isEmpty()) {
            return [];
        }

        $ids = $inventories->map(fn (Inventory $inventory): int => (int) $inventory->getKey())->all();

        $movements = InventoryMovement::query()
            ->whereIn('inventory_id', $ids)
            ->whereBetween('created_at', [$from, $until])
            ->get()
            ->groupBy('inventory_id');

        $latestSales = InventoryMovement::query()
            ->whereIn('inventory_id', $ids)
            ->where('type', InventoryMovementType::Sale)
            ->selectRaw('inventory_id, MAX(created_at) as last_sale_at')
            ->groupBy('inventory_id')
            ->pluck('last_sale_at', 'inventory_id');

        return $inventories->map(function (Inventory $inventory) use ($movements, $latestSales, $from, $until): array {
            $rows = $movements->get($inventory->getKey(), collect());

            $salesByDay = [];
            $returnsByDay = [];
            $stockoutDays = [];
            $salesUnits = 0;
            $returnUnits = 0;

            foreach ($rows as $movement) {
                $date = $movement->created_at->toDateString();
                $type = $movement->type;

                if ($type === InventoryMovementType::Sale) {
                    $units = abs((int) $movement->quantity);
                    $salesUnits += $units;
                    $salesByDay[$date] = ($salesByDay[$date] ?? 0) + $units;
                }

                if ($type === InventoryMovementType::ReturnIn) {
                    $units = max(0, (int) $movement->quantity);
                    $returnUnits += $units;
                    $returnsByDay[$date] = ($returnsByDay[$date] ?? 0) + $units;
                }

                if (
                    in_array($type, [InventoryMovementType::Sale, InventoryMovementType::ReturnOut, InventoryMovementType::Adjustment], true)
                    && (int) $movement->quantity < 0
                    && (int) $movement->balance_after <= 0
                ) {
                    $stockoutDays[$date] = true;
                }
            }

            $netUnitsSold = max(0, $salesUnits - $returnUnits);
            $activeSalesDays = count(array_filter($salesByDay, fn (int $units): bool => $units > 0));

            $createdAt = $inventory->created_at?->copy() ?? $from->copy();
            $analysisStart = $createdAt->greaterThan($from) ? $createdAt->copy()->startOfDay() : $from->copy()->startOfDay();
            $windowDays = (int) $from->copy()->startOfDay()->diffInDays($until->copy()->startOfDay()) + 1;
            $observedDays = $analysisStart->greaterThan($until)
                ? 0
                : min($windowDays, (int) $analysisStart->diffInDays($until->copy()->startOfDay()) + 1);

            $forecast = $this->forecast($netUnitsSold, $salesByDay, $returnsByDay, $observedDays, $activeSalesDays, $analysisStart, $until);
            $replenishment = $this->replenishment($forecast['daily_demand'], $inventory->available(), $inventory->low_stock_threshold);

            $lastSaleAt = $latestSales->get($inventory->getKey());
            $lastSaleAt = $lastSaleAt !== null ? Carbon::parse($lastSaleAt) : null;
            $slowMoving = $this->isSlowMoving($inventory, $lastSaleAt);
            $lastStockoutAt = $stockoutDays === [] ? null : max(array_keys($stockoutDays));
            $isOutOfStock = $inventory->available() <= 0;
            $isLowStock = $inventory->low_stock_threshold !== null
                && $inventory->available() <= $inventory->low_stock_threshold;

            $status = match (true) {
                $isOutOfStock => 'out_of_stock',
                $isLowStock => 'low_stock',
                $replenishment['needed'] => 'reorder',
                $slowMoving => 'slow_moving',
                default => 'healthy',
            };

            $unitCost = $this->unitCost($inventory);
            $value = $unitCost !== null ? round($unitCost * $inventory->quantity, 2) : null;
            $currency = $inventory->store?->currency ?? $inventory->product?->currency ?? 'USD';

            return [
                'id' => $inventory->getKey(),
                'store_id' => $inventory->store_id,
                'store' => $inventory->store?->name,
                'product_id' => $inventory->product_id,
                'product_variant_id' => $inventory->product_variant_id,
                'product_name' => $inventory->product?->name ?? $inventory->variant?->name ?? 'Unknown product',
                'variant_name' => $inventory->variant?->name,
                'sku' => $inventory->variant?->sku ?? $inventory->product?->sku,
                'currency' => $currency,
                'quantity' => $inventory->quantity,
                'reserved_quantity' => $inventory->reserved_quantity,
                'available' => $inventory->available(),
                'low_stock_threshold' => $inventory->low_stock_threshold,
                'is_low_stock' => $isLowStock,
                'is_out_of_stock' => $isOutOfStock,
                'is_slow_moving' => $slowMoving,
                'status' => $status,
                'unit_cost' => $unitCost,
                'inventory_value' => $value,
                'cost_reliable' => $unitCost !== null,
                'units_sold' => $netUnitsSold,
                'stockout_events' => count($stockoutDays),
                'last_stockout_at' => $lastStockoutAt,
                'last_sale_at' => $lastSaleAt?->toIso8601String(),
                'days_since_last_sale' => $lastSaleAt !== null ? (int) $lastSaleAt->diffInDays(now()) : null,
                'age_days' => $inventory->created_at !== null ? (int) $inventory->created_at->diffInDays(now()) : null,
                'forecast' => $forecast,
                'replenishment' => $replenishment,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, int>  $salesByDay
     * @param  array<string, int>  $returnsByDay
     * @return array<string, mixed>
     */
    private function forecast(
        int $netUnitsSold,
        array $salesByDay,
        array $returnsByDay,
        int $observedDays,
        int $activeSalesDays,
        Carbon $analysisStart,
        Carbon $until,
    ): array {
        $minimumHistory = max(1, (int) config('inventory.forecast.minimum_history_days', 14));
        $recentWindow = max(1, (int) config('inventory.forecast.recent_window_days', 7));
        $recentWeight = min(1.0, max(0.0, (float) config('inventory.forecast.recent_weight', 0.6)));
        $recentDays = min($recentWindow, $observedDays);
        $recentUnits = 0;

        for ($offset = 0; $offset < $recentDays; $offset++) {
            $date = $until->copy()->startOfDay()->subDays($offset);

            if ($date->lessThan($analysisStart)) {
                continue;
            }

            $net = ($salesByDay[$date->toDateString()] ?? 0) - ($returnsByDay[$date->toDateString()] ?? 0);
            $recentUnits += max(0, $net);
        }

        $baselineDaily = $observedDays > 0 ? $netUnitsSold / $observedDays : null;
        $recentDaily = $recentDays > 0 ? $recentUnits / $recentDays : $baselineDaily;
        $hasEnoughHistory = $observedDays >= $minimumHistory;
        $dailyDemand = $hasEnoughHistory && $baselineDaily !== null && $recentDaily !== null
            ? round(($recentDaily * $recentWeight) + ($baselineDaily * (1 - $recentWeight)), 2)
            : null;

        $confidence = 'low';

        if ($observedDays >= (int) config('inventory.forecast.high_confidence_days', 56)
            && $activeSalesDays >= (int) config('inventory.forecast.high_active_days', 7)) {
            $confidence = 'high';
        } elseif ($observedDays >= (int) config('inventory.forecast.medium_confidence_days', 28)
            && $activeSalesDays >= (int) config('inventory.forecast.medium_active_days', 3)) {
            $confidence = 'medium';
        }

        return [
            'status' => $hasEnoughHistory ? 'estimated' : 'insufficient_history',
            'daily_demand' => $dailyDemand,
            'confidence' => $confidence,
            'method' => 'weighted_recent_average',
            'window_days' => (int) $analysisStart->diffInDays($until->copy()->startOfDay()) + 1,
            'observed_days' => $observedDays,
            'recent_window_days' => $recentDays,
            'recent_weight' => $recentWeight,
            'active_sales_days' => $activeSalesDays,
            'net_units_sold' => $netUnitsSold,
            'minimum_history_days' => $minimumHistory,
            'notes' => [
                'returns_reduce_net_demand',
                'cancelled_orders_are_not_sales',
                'open_orders_are_reserved_not_sold',
                'lead_time_is_configuration_not_supplier_data',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replenishment(?float $dailyDemand, int $available, ?int $threshold): array
    {
        $leadTime = max(0, (int) config('inventory.reorder.lead_time_days', 7));
        $safetyDays = max(0, (int) config('inventory.reorder.safety_days', 3));
        $reviewDays = max(0, (int) config('inventory.reorder.review_period_days', 14));

        if ($dailyDemand !== null) {
            $reorderPoint = (int) ceil($dailyDemand * ($leadTime + $safetyDays));
            $target = (int) ceil($dailyDemand * ($leadTime + $safetyDays + $reviewDays));

            if ($threshold !== null) {
                $reorderPoint = max($reorderPoint, $threshold);
                $target = max($target, $threshold + 1);
            }

            $needed = $available <= $reorderPoint;
            $suggested = $needed ? max(0, $target - $available) : 0;

            return [
                'needed' => $needed,
                'basis' => 'forecast',
                'reorder_point' => $reorderPoint,
                'target_stock' => $target,
                'suggested_quantity' => $suggested,
                'days_of_cover' => $dailyDemand > 0 ? round(max(0, $available) / $dailyDemand, 1) : null,
                'lead_time_days' => $leadTime,
                'safety_days' => $safetyDays,
                'review_period_days' => $reviewDays,
            ];
        }

        if ($threshold !== null && $threshold > 0 && $available <= $threshold) {
            return [
                'needed' => true,
                'basis' => 'threshold',
                'reorder_point' => $threshold,
                'target_stock' => $threshold + 1,
                'suggested_quantity' => max(0, ($threshold + 1) - $available),
                'days_of_cover' => null,
                'lead_time_days' => $leadTime,
                'safety_days' => $safetyDays,
                'review_period_days' => $reviewDays,
            ];
        }

        return [
            'needed' => false,
            'basis' => 'none',
            'reorder_point' => $threshold,
            'target_stock' => null,
            'suggested_quantity' => 0,
            'days_of_cover' => null,
            'lead_time_days' => $leadTime,
            'safety_days' => $safetyDays,
            'review_period_days' => $reviewDays,
        ];
    }

    private function isSlowMoving(Inventory $inventory, ?Carbon $lastSaleAt): bool
    {
        $slowDays = max(1, (int) config('inventory.slow_moving_days', 60));
        $cutoff = now()->subDays($slowDays);

        if ($inventory->created_at === null || $inventory->created_at->greaterThan($cutoff)) {
            return false;
        }

        return $lastSaleAt === null || $lastSaleAt->lessThanOrEqualTo($cutoff);
    }

    private function unitCost(Inventory $inventory): ?float
    {
        $variantCost = (float) ($inventory->variant?->cost ?? 0);

        if ($variantCost > 0) {
            return $variantCost;
        }

        $productCost = (float) ($inventory->product?->cost ?? 0);

        return $productCost > 0 ? $productCost : null;
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    private function summary(array $metrics): array
    {
        $summary = [
            'items_count' => count($metrics),
            'store_count' => count(array_unique(array_column($metrics, 'store_id'))),
            'units_on_hand' => 0,
            'units_available' => 0,
            'units_reserved' => 0,
            'units_sold' => 0,
            'low_stock_count' => 0,
            'out_of_stock_count' => 0,
            'slow_moving_count' => 0,
            'reorder_count' => 0,
            'forecast_ready_count' => 0,
            'stockout_events' => 0,
            'valuation' => [],
        ];

        $valuation = [];

        foreach ($metrics as $item) {
            $summary['units_on_hand'] += $item['quantity'];
            $summary['units_available'] += $item['available'];
            $summary['units_reserved'] += $item['reserved_quantity'];
            $summary['units_sold'] += $item['units_sold'];
            $summary['low_stock_count'] += $item['is_low_stock'] ? 1 : 0;
            $summary['out_of_stock_count'] += $item['is_out_of_stock'] ? 1 : 0;
            $summary['slow_moving_count'] += $item['is_slow_moving'] ? 1 : 0;
            $summary['reorder_count'] += $item['replenishment']['needed'] ? 1 : 0;
            $summary['forecast_ready_count'] += $item['forecast']['status'] === 'estimated' ? 1 : 0;
            $summary['stockout_events'] += $item['stockout_events'];

            if ($item['inventory_value'] !== null) {
                $currency = $item['currency'];
                $valuation[$currency] ??= ['currency' => $currency, 'value' => 0.0, 'items' => 0];
                $valuation[$currency]['value'] += (float) $item['inventory_value'];
                $valuation[$currency]['items']++;
            }
        }

        ksort($valuation);
        $summary['valuation'] = array_values(array_map(function (array $entry): array {
            $entry['value'] = round($entry['value'], 2);

            return $entry;
        }, $valuation));

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return list<array<string, mixed>>
     */
    private function storeBreakdown(array $metrics): array
    {
        $stores = [];

        foreach ($metrics as $item) {
            $id = $item['store_id'];
            $stores[$id] ??= [
                'store_id' => $id,
                'store' => $item['store'],
                'currency' => $item['currency'],
                'items_count' => 0,
                'units_available' => 0,
                'low_stock_count' => 0,
                'out_of_stock_count' => 0,
                'reorder_count' => 0,
                'inventory_value' => 0.0,
            ];

            $stores[$id]['items_count']++;
            $stores[$id]['units_available'] += $item['available'];
            $stores[$id]['low_stock_count'] += $item['is_low_stock'] ? 1 : 0;
            $stores[$id]['out_of_stock_count'] += $item['is_out_of_stock'] ? 1 : 0;
            $stores[$id]['reorder_count'] += $item['replenishment']['needed'] ? 1 : 0;
            $stores[$id]['inventory_value'] += (float) ($item['inventory_value'] ?? 0);
        }

        $stores = array_values(array_map(function (array $store): array {
            $store['inventory_value'] = round($store['inventory_value'], 2);

            return $store;
        }, $stores));

        usort($stores, fn (array $left, array $right): int => strcmp((string) $left['store'], (string) $right['store']));

        return $stores;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function series(Inventory $inventory, Carbon $from, Carbon $until): array
    {
        $series = [];

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($until); $day->addDay()) {
            $series[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'sold' => 0,
                'returned' => 0,
                'on_hand' => null,
            ];
        }

        $movements = InventoryMovement::query()
            ->where('inventory_id', $inventory->getKey())
            ->whereBetween('created_at', [$from, $until])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $date = $movement->created_at->toDateString();

            if (! isset($series[$date])) {
                continue;
            }

            if ($movement->type === InventoryMovementType::Sale) {
                $series[$date]['sold'] += abs((int) $movement->quantity);
            }

            if ($movement->type === InventoryMovementType::ReturnIn) {
                $series[$date]['returned'] += max(0, (int) $movement->quantity);
            }

            $series[$date]['on_hand'] = (int) $movement->balance_after;
        }

        return array_values($series);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesStatus(array $item, string $status): bool
    {
        return match ($status) {
            'low' => $item['is_low_stock'] || $item['is_out_of_stock'],
            'out' => $item['is_out_of_stock'],
            'slow' => $item['is_slow_moving'],
            'reorder' => $item['replenishment']['needed'],
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function sortWeight(array $item): int
    {
        return match (true) {
            $item['is_out_of_stock'] => 0,
            $item['replenishment']['needed'] => 1,
            $item['is_low_stock'] => 2,
            $item['is_slow_moving'] => 3,
            default => 4,
        };
    }

    /**
     * @return array{from: string, until: string, days: int}
     */
    private function rangePayload(Carbon $from, Carbon $until): array
    {
        return [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'days' => (int) $from->copy()->startOfDay()->diffInDays($until->copy()->startOfDay()) + 1,
        ];
    }

    /**
     * @return array<string, int|float|bool>
     */
    private function settingsPayload(): array
    {
        return [
            'lead_time_days' => (int) config('inventory.reorder.lead_time_days', 7),
            'safety_days' => (int) config('inventory.reorder.safety_days', 3),
            'review_period_days' => (int) config('inventory.reorder.review_period_days', 14),
            'forecast_window_days' => (int) config('inventory.forecast.window_days', 28),
            'minimum_history_days' => (int) config('inventory.forecast.minimum_history_days', 14),
            'slow_moving_days' => (int) config('inventory.slow_moving_days', 60),
            'allow_negative_stock' => (bool) config('inventory.allow_negative_stock', false),
        ];
    }
}
