<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Services\Currency\CurrencyCatalog;
use App\Services\Currency\StoreCurrencyService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Profit reporting built only from figures Dukavo actually stores.
 *
 * Money is never added across currencies. A report is always expressed in one
 * currency, chosen by the caller and defaulting to the tenant's own. Calendar
 * days are evaluated in the tenant's timezone, while the database query uses
 * the equivalent UTC instants.
 *
 * Gross profit is reported only when every recognised line has a historical
 * unit-cost snapshot. When a snapshot is missing the result is null and the
 * uncovered units are returned, rather than valuing old stock at today's cost.
 * Net profit is always null: Dukavo has no shipping-cost or operating-cost
 * ledger to subtract.
 */
class ProfitIntelligenceService
{
    /**
     * The currency the current report is expressed in. Every `money()` call
     * inside one `overview()` belongs to this currency, so rounding uses its
     * own minor unit rather than a blanket two decimals.
     */
    private string $reportCurrency = 'USD';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StoreCurrencyService $currencies,
        private readonly CurrencyCatalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(
        ?Store $store,
        string $currency,
        CarbonImmutable $from,
        CarbonImmutable $until,
        int $productLimit = 25,
    ): array {
        $context = $this->context($currency);
        [$utcFrom, $utcUntil] = $this->utcBounds($from, $until, $context['timezone']);

        $rates = $this->reportingRates($currency, $utcFrom, $utcUntil);

        $orderRows = $this->orderRows($store, $rates, $utcFrom, $utcUntil, $context['timezone']);
        $products = $this->productRows($store, $rates, $utcFrom, $utcUntil, $productLimit);
        $summary = $this->summary($orderRows);
        $recognised = $orderRows->where('is_recognized', true)->values();
        $refunded = $orderRows
            ->filter(fn (array $row): bool => $row['status'] !== OrderStatus::Cancelled->value
                && $row['payment_status'] === PaymentStatus::Refunded->value)
            ->values();

        $range = [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'days' => (int) $from->diffInDays($until) + 1,
            'timezone' => $context['timezone'],
        ];

        $limitations = $this->limitations($summary);

        if ($this->hasUnconvertibleCurrencies($rates)) {
            $limitations[] = [
                'code' => 'historical_currency_unconvertible',
                'severity' => 'warning',
                'affects' => ['revenue', 'net_sales', 'gross_profit'],
            ];
        }

        return [
            'currency' => $currency,
            'currencies' => $this->currencies($currency),
            'exchange_rates' => $rates,
            'range' => $range,
            'summary' => $summary,
            'series' => $this->series($recognised, $range, $context['timezone']),
            'products' => $products,
            'stores' => $this->storeBreakdown($recognised),
            'statuses' => $this->statusBreakdown($orderRows),
            'payments' => $this->paymentSummary($orderRows),
            'refunds' => [
                'refunded_orders' => $refunded->count(),
                'refunded_order_value' => $this->money($refunded->sum('grand_total')),
                'refund_rate_percent' => $this->rate(
                    $refunded->count(),
                    $recognised->count() + $refunded->count(),
                ),
                'refund_amount_recorded' => false,
            ],
            'methodology' => [
                'revenue_recognition' => 'placed_orders_excluding_cancelled_and_refunded',
                'gross_sales' => 'order_subtotal',
                'net_sales' => 'order_subtotal_minus_discounts',
                'cogs' => 'snapshotted_unit_cost_times_quantity',
                'gross_profit' => 'net_sales_minus_cogs',
                'gross_margin' => 'gross_profit_over_net_sales',
                'tax_treatment' => 'reported_separately_not_in_sales',
                'shipping_treatment' => 'revenue_reported_separately_cost_not_recorded',
                'cost_source' => 'order_item_cost_snapshot',
                'currency' => $currency,
                'currency_policy' => 'historical_amounts_converted_with_stored_snapshot_rate',
                'timezone' => $context['timezone'],
                'excluded_order_statuses' => $this->excludedOrderStatuses(),
                'excluded_payment_statuses' => $this->excludedPaymentStatuses(),
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * One row per order, for a spreadsheet-safe export. Excluded orders are
     * present with `recognized` false so the file reconciles with the screen.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(
        ?Store $store,
        string $currency,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        $currency = strtoupper($currency);
        $this->reportCurrency = $currency;
        $context = $this->context($currency);
        [$utcFrom, $utcUntil] = $this->utcBounds($from, $until, $context['timezone']);
        $rates = $this->reportingRates($currency, $utcFrom, $utcUntil);

        return $this->orderRows($store, $rates, $utcFrom, $utcUntil, $context['timezone'])
            ->map(fn (array $row): array => [
                'order_number' => $row['order_number'],
                'store' => $row['store_name'],
                'placed_at' => $row['placed_at']->setTimezone($context['timezone'])->format('Y-m-d H:i:s'),
                'status' => $row['status'],
                'payment_status' => $row['payment_status'],
                'currency' => $row['currency'],
                'reporting_currency' => $currency,
                'exchange_rate' => $row['exchange_rate'],
                'recognized' => $row['is_recognized'] ? 'yes' : 'no',
                'gross_sales' => $row['subtotal'],
                'discounts' => $row['discount_total'],
                'net_sales' => $row['is_recognized'] ? $row['net_sales'] : null,
                'tax_collected' => $row['tax_total'],
                'shipping_revenue' => $row['shipping_total'],
                'cogs' => $row['is_recognized'] ? $row['cogs'] : null,
                'cost_complete' => $row['cost_complete'] ? 'yes' : 'no',
                'uncosted_units' => $row['uncosted_units'],
                'gross_profit' => $row['gross_profit'],
                'grand_total' => $row['grand_total'],
            ])
            ->all();
    }

    /**
     * Every currency the workspace has actually used, plus the selected one.
     * The UI offers these as a filter; it never combines them.
     *
     * @return list<string>
     */
    private function currencies(string $selected): array
    {
        $currencies = Order::query()
            ->whereNotNull('placed_at')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->map(fn (mixed $currency): string => strtoupper((string) $currency))
            ->unique()
            ->values()
            ->all();

        if (! in_array($selected, $currencies, true)) {
            $currencies[] = $selected;
        }

        sort($currencies);

        return array_values($currencies);
    }

    /**
     * A reporting rate for every currency present in the range.
     *
     * Historical orders keep the currency they were placed in. To express them
     * in the selected reporting currency, each one is converted with the rate
     * snapshot recorded when the store changed currency — never with a live
     * quote, which would silently restate past revenue every time the market
     * moved. A currency with no stored conversion is `null` and is reported as
     * unconvertible instead of being guessed at.
     *
     * @return array<string, float|null>
     */
    private function reportingRates(
        string $reportingCurrency,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        $reportingCurrency = strtoupper($reportingCurrency);

        $codes = Order::query()
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [$from, $until])
            ->distinct()
            ->pluck('currency')
            ->map(fn (mixed $code): string => strtoupper((string) $code))
            ->push($reportingCurrency)
            ->unique()
            ->values();

        $rates = [];

        foreach ($codes as $code) {
            if ($code === $reportingCurrency) {
                $rates[$code] = 1.0;

                continue;
            }

            $rates[$code] = $this->currencies->storedRate($code, $reportingCurrency)?->rate;
        }

        return $rates;
    }

    /**
     * @param  array<string, float|null>  $rates
     */
    private function hasUnconvertibleCurrencies(array $rates): bool
    {
        return collect($rates)->contains(fn (?float $rate): bool => $rate === null);
    }

    /**
     * @return array{name: string, currency: string, timezone: string}
     */
    private function context(string $currency): array
    {
        $tenant = $this->tenants->required();
        $timezone = (string) ($tenant->timezone ?: config('app.timezone', 'UTC'));

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = 'UTC';
        }

        return [
            'name' => (string) $tenant->name,
            'currency' => strtoupper($currency),
            'timezone' => $timezone,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcBounds(CarbonImmutable $from, CarbonImmutable $until, string $timezone): array
    {
        $start = CarbonImmutable::parse($from->toDateString(), $timezone)->startOfDay();
        $end = CarbonImmutable::parse($until->toDateString(), $timezone)->endOfDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * @param  array<string, float|null>  $rates  Order currency → reporting rate.
     * @return Collection<int, array<string, mixed>>
     */
    private function orderRows(
        ?Store $store,
        array $rates,
        CarbonImmutable $from,
        CarbonImmutable $until,
        string $timezone,
    ): Collection {
        $orders = Order::query()
            ->with(['store:id,name'])
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [$from, $until])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->get();

        $costs = $this->costsByOrder($store, $from, $until);

        return $orders
            ->filter(fn (Order $order): bool => ($rates[strtoupper((string) $order->currency)] ?? null) !== null)
            ->map(fn (Order $order): array => $this->shapeOrder(
                $order,
                $costs->get($order->getKey()),
                (float) $rates[strtoupper((string) $order->currency)],
                $timezone,
            ))
            ->sortBy('placed_at')
            ->values();
    }

    /**
     * Known cost and cost coverage per order, in one grouped query.
     *
     * @return Collection<int, object>
     */
    private function costsByOrder(
        ?Store $store,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): Collection {
        $tenantId = (int) $this->tenants->required()->getKey();

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.placed_at', [$from, $until])
            ->when($store, fn (Builder $query) => $query->where('orders.store_id', $store->getKey()))
            ->selectRaw('orders.id as order_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NOT NULL AND order_items.cost_currency = orders.currency THEN order_items.unit_cost * order_items.quantity ELSE 0 END), 0) as cogs')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL OR order_items.cost_currency IS NULL OR order_items.cost_currency <> orders.currency THEN order_items.quantity ELSE 0 END), 0) as uncosted_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL OR order_items.cost_currency IS NULL OR order_items.cost_currency <> orders.currency THEN (order_items.unit_price * order_items.quantity) - order_items.discount_total ELSE 0 END), 0) as uncosted_net_sales')
            ->groupBy('orders.id')
            ->get()
            ->keyBy('order_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeOrder(Order $order, ?object $cost, float $rate, string $timezone): array
    {
        $status = $order->status->value;
        $paymentStatus = $order->payment_status->value;
        $recognized = ! in_array($status, $this->excludedOrderStatuses(), true)
            && ! in_array($paymentStatus, $this->excludedPaymentStatuses(), true);

        $subtotal = $this->money((float) $order->subtotal * $rate);
        $discount = $this->money((float) $order->discount_total * $rate);
        $netSales = $this->money($subtotal - $discount);
        $units = (int) ($cost->units ?? 0);
        $cogs = $this->money((float) ($cost->cogs ?? 0) * $rate);
        $uncostedUnits = (int) ($cost->uncosted_units ?? 0);
        $costComplete = $units > 0 && $uncostedUnits === 0;
        $placedAt = CarbonImmutable::parse($order->placed_at);

        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'store_id' => (int) $order->store_id,
            'store_name' => $order->store?->name,
            'placed_at' => $placedAt,
            'placed_at_local' => $placedAt->setTimezone($timezone)->toDateString(),
            'status' => $status,
            'payment_status' => $paymentStatus,
            'currency' => strtoupper((string) $order->currency),
            'exchange_rate' => $rate,
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'net_sales' => $netSales,
            'tax_total' => $this->money((float) $order->tax_total * $rate),
            'shipping_total' => $this->money((float) $order->shipping_total * $rate),
            'grand_total' => $this->money((float) $order->grand_total * $rate),
            'is_recognized' => $recognized,
            'units' => $units,
            'cogs' => $recognized ? $cogs : null,
            'uncosted_units' => $uncostedUnits,
            'uncosted_net_sales' => $this->money((float) ($cost->uncosted_net_sales ?? 0) * $rate),
            'cost_complete' => $recognized && $costComplete,
            'gross_profit' => $recognized && $costComplete ? $this->money($netSales - $cogs) : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $recognised = $rows->where('is_recognized', true)->values();
        $cancelled = $rows
            ->filter(fn (array $row): bool => $row['status'] === OrderStatus::Cancelled->value)
            ->values();
        $refunded = $rows
            ->filter(fn (array $row): bool => $row['status'] !== OrderStatus::Cancelled->value
                && $row['payment_status'] === PaymentStatus::Refunded->value)
            ->values();

        $grossSales = $this->money($recognised->sum('subtotal'));
        $discounts = $this->money($recognised->sum('discount_total'));
        $netSales = $this->money($grossSales - $discounts);
        $units = (int) $recognised->sum('units');
        $cogs = $this->money($recognised->sum('cogs'));
        $uncostedUnits = (int) $recognised->sum('uncosted_units');
        $costComplete = $units > 0 && $uncostedUnits === 0;
        $grossProfit = $costComplete ? $this->money($netSales - $cogs) : null;

        return [
            'total_orders' => $rows->count(),
            'recognized_orders' => $recognised->count(),
            'gross_sales' => $grossSales,
            'discounts' => $discounts,
            'net_sales' => $netSales,
            'tax_collected' => $this->money($recognised->sum('tax_total')),
            'shipping_revenue' => $this->money($recognised->sum('shipping_total')),
            'recognized_order_value' => $this->money($recognised->sum('grand_total')),
            'average_order_value' => $recognised->count() > 0
                ? $this->money($recognised->sum('grand_total') / $recognised->count())
                : null,
            'units_sold' => $units,
            'cogs' => $cogs,
            'cost_complete' => $costComplete,
            'uncosted_units' => $uncostedUnits,
            'uncosted_net_sales' => $this->money($recognised->sum('uncosted_net_sales')),
            'cost_coverage_percent' => $units > 0
                ? round((($units - $uncostedUnits) / $units) * 100, 2)
                : null,
            'gross_profit' => $grossProfit,
            'gross_margin_percent' => $grossProfit !== null && $netSales > 0
                ? round(($grossProfit / $netSales) * 100, 2)
                : null,
            'shipping_cost' => null,
            'operating_costs' => null,
            'net_profit' => null,
            'cancelled_orders' => $cancelled->count(),
            'cancelled_order_value' => $this->money($cancelled->sum('grand_total')),
            'cancellation_rate_percent' => $this->rate($cancelled->count(), $rows->count()),
            'refunded_orders' => $refunded->count(),
            'refunded_order_value' => $this->money($refunded->sum('grand_total')),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $recognised
     * @param  array{from: string, until: string, days: int, timezone: string}  $range
     * @return list<array<string, mixed>>
     */
    private function series(Collection $recognised, array $range, string $timezone): array
    {
        $buckets = [];

        for (
            $day = CarbonImmutable::parse($range['from'], $timezone);
            $day->lte(CarbonImmutable::parse($range['until'], $timezone));
            $day = $day->addDay()
        ) {
            $buckets[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'orders' => 0,
                'gross_sales' => 0.0,
                'discounts' => 0.0,
                'net_sales' => 0.0,
                'recognized_order_value' => 0.0,
                'cogs' => 0.0,
                'units' => 0,
                'uncosted_units' => 0,
            ];
        }

        foreach ($recognised as $row) {
            $key = $row['placed_at_local'];

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['orders']++;
            $buckets[$key]['gross_sales'] += $row['subtotal'];
            $buckets[$key]['discounts'] += $row['discount_total'];
            $buckets[$key]['net_sales'] += $row['net_sales'];
            $buckets[$key]['recognized_order_value'] += $row['grand_total'];
            $buckets[$key]['cogs'] += (float) $row['cogs'];
            $buckets[$key]['units'] += $row['units'];
            $buckets[$key]['uncosted_units'] += $row['uncosted_units'];
        }

        return array_values(array_map(function (array $day): array {
            $complete = $day['units'] > 0 && $day['uncosted_units'] === 0;

            return [
                'date' => $day['date'],
                'orders' => $day['orders'],
                'gross_sales' => $this->money($day['gross_sales']),
                'discounts' => $this->money($day['discounts']),
                'net_sales' => $this->money($day['net_sales']),
                'recognized_order_value' => $this->money($day['recognized_order_value']),
                'cogs' => $this->money($day['cogs']),
                'gross_profit' => $complete ? $this->money($day['net_sales'] - $day['cogs']) : null,
                'cost_complete' => $complete,
            ];
        }, $buckets));
    }

    /**
     * @param  array<string, float|null>  $rates  Order currency → reporting rate.
     * @return list<array<string, mixed>>
     */
    private function productRows(
        ?Store $store,
        array $rates,
        CarbonImmutable $from,
        CarbonImmutable $until,
        int $limit,
    ): array {
        $tenantId = (int) $this->tenants->required()->getKey();

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.placed_at', [$from, $until])
            ->whereNotIn('orders.status', $this->excludedOrderStatuses())
            ->whereNotIn('orders.payment_status', $this->excludedPaymentStatuses())
            ->when($store, fn (Builder $query) => $query->where('orders.store_id', $store->getKey()))
            ->selectRaw('order_items.product_id, order_items.name, order_items.sku, orders.currency as order_currency')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(order_items.unit_price * order_items.quantity), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(order_items.discount_total), 0) as discounts')
            ->selectRaw('COALESCE(SUM((order_items.unit_price * order_items.quantity) - order_items.discount_total), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NOT NULL AND order_items.cost_currency = orders.currency THEN order_items.unit_cost * order_items.quantity ELSE 0 END), 0) as cogs')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL OR order_items.cost_currency IS NULL OR order_items.cost_currency <> orders.currency THEN order_items.quantity ELSE 0 END), 0) as uncosted_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL OR order_items.cost_currency IS NULL OR order_items.cost_currency <> orders.currency THEN (order_items.unit_price * order_items.quantity) - order_items.discount_total ELSE 0 END), 0) as uncosted_net_sales')
            ->groupBy('order_items.product_id', 'order_items.name', 'order_items.sku', 'orders.currency')
            ->get();

        // Groups are split by order currency, converted, then merged per product.
        // Converting after aggregation keeps the stored per-order figures exact,
        // and merging afterwards means one product sold in two currencies still
        // produces a single report line.
        $products = [];

        foreach ($rows as $item) {
            $currency = strtoupper((string) $item->getAttribute('order_currency'));
            $rate = $rates[$currency] ?? null;

            if ($rate === null) {
                continue;
            }

            $productId = $item->getAttribute('product_id') === null
                ? null
                : (int) $item->getAttribute('product_id');
            $name = (string) $item->getAttribute('name');
            $sku = $item->getAttribute('sku');
            $key = implode('|', [$productId ?? 'null', $name, $sku ?? '']);

            $products[$key] ??= [
                'product_id' => $productId,
                'name' => $name,
                'sku' => $sku,
                'orders' => 0,
                'units' => 0,
                'gross_sales' => 0.0,
                'discounts' => 0.0,
                'net_sales' => 0.0,
                'cogs' => 0.0,
                'uncosted_units' => 0,
                'uncosted_net_sales' => 0.0,
            ];

            $products[$key]['orders'] += (int) $item->getAttribute('orders');
            $products[$key]['units'] += (int) $item->getAttribute('units');
            $products[$key]['gross_sales'] += (float) $item->getAttribute('gross_sales') * $rate;
            $products[$key]['discounts'] += (float) $item->getAttribute('discounts') * $rate;
            $products[$key]['net_sales'] += (float) $item->getAttribute('net_sales') * $rate;
            $products[$key]['cogs'] += (float) $item->getAttribute('cogs') * $rate;
            $products[$key]['uncosted_units'] += (int) $item->getAttribute('uncosted_units');
            $products[$key]['uncosted_net_sales'] += (float) $item->getAttribute('uncosted_net_sales') * $rate;
        }

        return collect($products)
            ->map(function (array $product): array {
                $units = (int) $product['units'];
                $uncostedUnits = (int) $product['uncosted_units'];
                $netSales = $this->money($product['net_sales']);
                $cogs = $this->money($product['cogs']);
                $complete = $units > 0 && $uncostedUnits === 0;

                return [
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'orders' => (int) $product['orders'],
                    'units' => $units,
                    'gross_sales' => $this->money($product['gross_sales']),
                    'discounts' => $this->money($product['discounts']),
                    'net_sales' => $netSales,
                    'cogs' => $cogs,
                    'uncosted_units' => $uncostedUnits,
                    'uncosted_net_sales' => $this->money($product['uncosted_net_sales']),
                    'cost_complete' => $complete,
                    'gross_profit' => $complete ? $this->money($netSales - $cogs) : null,
                    'gross_margin_percent' => $complete && $netSales > 0
                        ? round((($netSales - $cogs) / $netSales) * 100, 2)
                        : null,
                ];
            })
            ->sortByDesc('net_sales')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $recognised
     * @return list<array<string, mixed>>
     */
    private function storeBreakdown(Collection $recognised): array
    {
        return $recognised
            ->groupBy('store_id')
            ->map(function (Collection $rows, int|string $storeId): array {
                $netSales = $this->money($rows->sum('net_sales'));
                $units = (int) $rows->sum('units');
                $uncostedUnits = (int) $rows->sum('uncosted_units');
                $cogs = $this->money($rows->sum('cogs'));
                $complete = $units > 0 && $uncostedUnits === 0;

                return [
                    'store_id' => (int) $storeId,
                    'store' => $rows->first()['store_name'],
                    'orders' => $rows->count(),
                    'net_sales' => $netSales,
                    'cogs' => $cogs,
                    'cost_complete' => $complete,
                    'gross_profit' => $complete ? $this->money($netSales - $cogs) : null,
                    'gross_margin_percent' => $complete && $netSales > 0
                        ? round((($netSales - $cogs) / $netSales) * 100, 2)
                        : null,
                    'average_order_value' => $this->money($rows->sum('grand_total') / $rows->count()),
                ];
            })
            ->sortByDesc('net_sales')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function statusBreakdown(Collection $rows): array
    {
        return collect(OrderStatus::cases())
            ->map(function (OrderStatus $status) use ($rows): array {
                $matching = $rows->where('status', $status->value);

                return [
                    'status' => $status->value,
                    'orders' => $matching->count(),
                    'recognized_orders' => $matching->where('is_recognized', true)->count(),
                    'order_value' => $this->money($matching->sum('grand_total')),
                    'net_sales' => $this->money($matching->where('is_recognized', true)->sum('net_sales')),
                    'share_percent' => $this->rate($matching->count(), $rows->count()),
                ];
            })
            ->all();
    }

    /**
     * Cash is shown only as far as the stored data allows. A partially paid
     * order has no record of how much was received, so the outstanding total is
     * withheld rather than understated by treating the whole order as unpaid.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function paymentSummary(Collection $rows): array
    {
        $recognised = $rows->where('is_recognized', true);
        $partial = $recognised->where('payment_status', PaymentStatus::PartiallyPaid->value);
        $unpaid = $recognised->where('payment_status', PaymentStatus::Unpaid->value);

        return [
            'marked_paid_value' => $this->money(
                $recognised->where('payment_status', PaymentStatus::Paid->value)->sum('grand_total'),
            ),
            'unpaid_value' => $this->money($unpaid->sum('grand_total')),
            'unpaid_orders' => $unpaid->count(),
            'partially_paid_value' => $this->money($partial->sum('grand_total')),
            'partially_paid_orders' => $partial->count(),
            'refunded_order_value' => $this->money(
                $rows->where('payment_status', PaymentStatus::Refunded->value)->sum('grand_total'),
            ),
            'collected_payments' => null,
            'outstanding_amount' => $partial->isEmpty()
                ? $this->money($unpaid->sum('grand_total'))
                : null,
            'statuses' => collect(PaymentStatus::cases())
                ->map(function (PaymentStatus $status) use ($rows): array {
                    $matching = $rows->where('payment_status', $status->value);

                    return [
                        'payment_status' => $status->value,
                        'orders' => $matching->count(),
                        'recognized_orders' => $matching->where('is_recognized', true)->count(),
                        'order_value' => $this->money($matching->sum('grand_total')),
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{code: string, severity: string, affects: list<string>}>
     */
    private function limitations(array $summary): array
    {
        $limitations = [
            [
                'code' => 'payment_amounts_not_recorded',
                'severity' => 'warning',
                'affects' => ['collected_payments', 'outstanding_amount', 'partial_payment_amount'],
            ],
            [
                'code' => 'refund_amounts_not_recorded',
                'severity' => 'warning',
                'affects' => ['refund_amount', 'net_profit'],
            ],
            [
                'code' => 'shipping_cost_not_recorded',
                'severity' => 'warning',
                'affects' => ['shipping_cost', 'net_profit'],
            ],
            [
                'code' => 'operating_costs_not_recorded',
                'severity' => 'warning',
                'affects' => ['operating_costs', 'net_profit'],
            ],
        ];

        if (! $summary['cost_complete']) {
            $limitations[] = [
                'code' => 'historical_cost_incomplete',
                'severity' => 'warning',
                'affects' => ['cogs', 'gross_profit', 'gross_margin'],
            ];
        }

        return $limitations;
    }

    /**
     * @return list<string>
     */
    private function excludedOrderStatuses(): array
    {
        return array_values(array_map(
            static fn (string $status): string => $status,
            (array) config('profit.recognition.excluded_order_statuses', ['cancelled']),
        ));
    }

    /**
     * @return list<string>
     */
    private function excludedPaymentStatuses(): array
    {
        return array_values(array_map(
            static fn (string $status): string => $status,
            (array) config('profit.recognition.excluded_payment_statuses', ['refunded']),
        ));
    }

    private function money(float|int|string|null $value): float
    {
        $decimals = $this->catalog->supports($this->reportCurrency)
            ? $this->catalog->decimals($this->reportCurrency)
            : 2;

        return round((float) $value, $decimals);
    }

    private function rate(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : null;
    }
}
