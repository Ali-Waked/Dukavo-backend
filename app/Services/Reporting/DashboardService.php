<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ConversationStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Read-only aggregates behind the dashboard.
 *
 * Every figure is computed in SQL rather than by loading rows into PHP, and the
 * whole set is produced with a small fixed number of queries so the dashboard
 * stays cheap as a tenant's order history grows. Every query is tenant scoped by
 * the global scope, so nothing here needs to filter by tenant explicitly.
 */
class DashboardService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Headline cards for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(?Store $store = null): array
    {
        $today = CarbonImmutable::today();
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $revenueToday = $this->revenueBetween($today, $today, $store);
        $revenueMonth = $this->revenueBetween($monthStart, CarbonImmutable::now(), $store);

        return [
            'revenue_today' => $revenueToday['total'],
            'orders_today' => $revenueToday['count'],
            'average_order_value' => $revenueToday['count'] > 0
                ? round($revenueToday['total'] / $revenueToday['count'], 2)
                : 0.0,
            'revenue_month' => $revenueMonth['total'],
            'orders_month' => $revenueMonth['count'],
            'open_conversations' => Conversation::query()
                ->withStatus(ConversationStatus::Open)
                ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
                ->count(),
            'low_stock_items' => Inventory::query()
                ->lowStock()
                ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
                ->count(),
            'active_customers' => Customer::query()->where('status', 'active')->count(),
            'awaiting_fulfilment' => Order::query()
                ->whereIn('status', [
                    OrderStatus::Pending->value,
                    OrderStatus::Confirmed->value,
                    OrderStatus::Processing->value,
                ])
                ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
                ->count(),
        ];
    }

    /**
     * Daily revenue for the last `$days` days, oldest first, so a chart can plot
     * it directly. Days with no orders are present as zeroes rather than missing,
     * otherwise a chart would compress quiet days out of the axis.
     *
     * @return list<array{date: string, total: float, orders: int}>
     */
    public function revenueSeries(int $days = 30, ?Store $store = null): array
    {
        $from = CarbonImmutable::today()->subDays($days - 1);

        $totals = Order::query()
            ->selectRaw('DATE(placed_at) as day, SUM(grand_total) as total, COUNT(*) as orders')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('placed_at')
            ->where('placed_at', '>=', $from->startOfDay())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->groupBy('day')
            ->pluck('total', 'day');

        $counts = Order::query()
            ->selectRaw('DATE(placed_at) as day, COUNT(*) as orders')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('placed_at')
            ->where('placed_at', '>=', $from->startOfDay())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->groupBy('day')
            ->pluck('orders', 'day');

        $series = [];

        for ($day = $from; $day->lte(CarbonImmutable::today()); $day = $day->addDay()) {
            $key = $day->toDateString();

            $series[] = [
                'date' => $key,
                'total' => round((float) ($totals[$key] ?? 0), 2),
                'orders' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Best sellers by units sold, for the products widget.
     *
     * `product_slug` travels with the row so the dashboard can link to the same
     * slug-addressed product page the catalogue uses. A product that has ever
     * been ordered is archived rather than soft deleted, so its slug still
     * resolves.
     *
     * @return list<array{product_id: int, product_slug: string|null, name: string, sku: string|null, units: int, revenue: float}>
     */
    public function topProducts(int $limit = 5, ?Store $store = null): array
    {
        return OrderItem::query()
            ->selectRaw('order_items.product_id, products.slug as product_slug, order_items.name, order_items.sku, SUM(order_items.quantity) as units, SUM(order_items.line_total) as revenue')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', [OrderStatus::Cancelled->value])
            ->when($store, fn ($query) => $query->where('orders.store_id', $store->getKey()))
            ->groupBy('order_items.product_id', 'products.slug', 'order_items.name', 'order_items.sku')
            ->orderByDesc('units')
            ->limit($limit)
            ->get()
            ->map(fn (OrderItem $item): array => [
                'product_id' => (int) $item->getAttribute('product_id'),
                'product_slug' => $item->getAttribute('product_slug'),
                'name' => (string) $item->getAttribute('name'),
                'sku' => $item->getAttribute('sku'),
                'units' => (int) $item->getAttribute('units'),
                'revenue' => round((float) $item->getAttribute('revenue'), 2),
            ])
            ->all();
    }

    /**
     * Products nobody has sold, which is what a merchant wants to know about
     * stock that is tying up money.
     *
     * @return list<array{id: int, name: string, sku: string|null, stock: int}>
     */
    public function deadStock(int $limit = 5): array
    {
        return Product::query()
            ->select('products.id', 'products.name', 'products.sku')
            ->withSum('orderItems as units_sold', 'quantity')
            ->withSum('inventories as stock_on_hand', 'quantity')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('order_items')
                    ->whereColumn('order_items.product_id', 'products.id');
            })
            ->orderByDesc('stock_on_hand')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'stock' => (int) ($product->getAttribute('stock_on_hand') ?? 0),
            ])
            ->all();
    }

    /**
     * The tenant's currency, so the frontend formats money without guessing.
     *
     * @return array{tenant: string, currency: string, timezone: string}
     */
    public function context(): array
    {
        $tenant = $this->tenants->required();

        return [
            'tenant' => $tenant->name,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
        ];
    }

    /**
     * @return array{total: float, count: int}
     */
    private function revenueBetween(CarbonImmutable $from, CarbonImmutable $until, ?Store $store): array
    {
        $row = Order::query()
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total, COUNT(*) as orders')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [$from->startOfDay(), $until->endOfDay()])
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->first();

        return [
            'total' => round((float) ($row->total ?? 0), 2),
            'count' => (int) ($row->orders ?? 0),
        ];
    }
}
