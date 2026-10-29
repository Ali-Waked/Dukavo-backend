<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MessageDirection;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecoveryOpportunityStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\RecoveryOpportunity;
use App\Models\Store;
use App\Services\Reporting\ProfitIntelligenceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only executive analytics.
 *
 * Sales figures come from the profit reporting service so a merchant can never
 * see two different definitions of revenue in the same workspace. Everything
 * else is computed from records Dukavo actually stores; when the data cannot
 * support a metric it is marked as insufficient instead of guessed.
 *
 * Every query runs under the tenant global scope. Store and currency filters
 * are applied on top, never instead of, that isolation.
 */
class AnalyticsService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ProfitIntelligenceService $profit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(
        ?Store $store,
        string $currency,
        CarbonImmutable $from,
        CarbonImmutable $until,
        int $productLimit = 10,
    ): array {
        $currency = strtoupper($currency);
        $current = $this->profit->overview($store, $currency, $from, $until, $productLimit);

        $days = (int) $from->diffInDays($until) + 1;
        $previousUntil = $from->subDay();
        $previousFrom = $previousUntil->subDays(max(0, $days - 1));
        $previous = $this->profit->overview($store, $currency, $previousFrom, $previousUntil, 1);

        $tz = (string) $current['range']['timezone'];
        [$utcFrom, $utcUntil] = $this->utcBounds($from, $until, $tz);

        $summary = $current['summary'];
        $previousSummary = $previous['summary'];

        return [
            'context' => $this->context($store, $currency),
            'range' => $current['range'],
            'comparison' => [
                'from' => $previousFrom->toDateString(),
                'until' => $previousUntil->toDateString(),
                'days' => $days,
                'timezone' => $tz,
            ],
            'currency' => $currency,
            'definitions' => AnalyticsMetricCatalog::definitions(),
            'kpis' => $this->kpis($summary, $previousSummary),
            'orders_over_time' => $this->ordersOverTime($store, $utcFrom, $utcUntil, $tz),
            'order_status_distribution' => $this->orderStatusDistribution($current['statuses']),
            'sales_trends' => $this->salesTrends($current['series']),
            'customer_repeat_purchase_rate' => $this->customerRepeatPurchaseRate(
                $store,
                $currency,
                $utcFrom,
                $utcUntil,
            ),
            'product_performance' => $this->productPerformance($current['products']),
            'store_performance' => $this->storePerformance($current['stores']),
            'stock_health' => $this->stockHealth($store),
            'conversation_response_metrics' => $this->conversationResponseMetrics($store, $utcFrom, $utcUntil),
            'sales_recovery_funnel' => $this->salesRecoveryFunnel($store, $currency, $utcFrom, $utcUntil),
            'message_delivery_metrics' => $this->messageDeliveryMetrics($store, $utcFrom, $utcUntil),
            'financial_margin' => $this->financialMargin($summary),
            'payment_summary' => $current['payments'],
            'refunds' => $current['refunds'],
            'limitations' => $current['limitations'],
            'methodology' => $current['methodology'],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $previous
     * @return list<array<string, mixed>>
     */
    private function kpis(array $summary, array $previous): array
    {
        return [
            $this->kpi(
                'revenue',
                'Revenue',
                (float) $summary['recognized_order_value'],
                'currency',
                (float) $previous['recognized_order_value'],
                'Sum of order totals for recognized orders in range (not profit).',
            ),
            $this->kpi(
                'recognized_orders',
                'Recognized Orders',
                (int) $summary['recognized_orders'],
                'count',
                (int) $previous['recognized_orders'],
                'Orders that count as a sale: placed, not cancelled, not refunded.',
            ),
            $this->kpi(
                'total_orders',
                'Total Orders',
                (int) $summary['total_orders'],
                'count',
                (int) $previous['total_orders'],
                'All orders placed in range, including cancelled and refunded orders.',
            ),
            $this->kpi(
                'average_order_value',
                'Average Order Value',
                (float) ($summary['average_order_value'] ?? 0),
                'currency',
                $previous['average_order_value'] === null ? null : (float) $previous['average_order_value'],
                'Revenue divided by recognized orders in range.',
            ),
            $this->kpi(
                'units_sold',
                'Units Sold',
                (int) $summary['units_sold'],
                'count',
                (int) $previous['units_sold'],
                'Quantity on recognized order lines in range.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(
        string $key,
        string $label,
        float|int $value,
        string $unit,
        float|int|null $previous,
        string $definition,
    ): array {
        $change = null;
        $direction = 'flat';

        if ($previous !== null) {
            if ($previous > 0) {
                $change = round((($value - $previous) / $previous) * 100, 2);
            }

            if ($value > $previous) {
                $direction = 'up';
            } elseif ($value < $previous) {
                $direction = 'down';
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'previous_value' => $previous,
            'change_percent' => $change,
            'direction' => $direction,
            'unit' => $unit,
            'definition' => $definition,
        ];
    }

    /**
     * @return list<array{date: string, orders: int}>
     */
    private function ordersOverTime(
        ?Store $store,
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
        string $timezone,
    ): array {
        $orders = Order::query()
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [$utcFrom, $utcUntil])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->get(['id', 'placed_at']);

        $buckets = $this->emptyDayBuckets($utcFrom, $utcUntil, $timezone);

        foreach ($orders as $order) {
            $key = CarbonImmutable::parse($order->placed_at)->setTimezone($timezone)->toDateString();

            if (isset($buckets[$key])) {
                $buckets[$key]++;
            }
        }

        return array_map(
            static fn (string $date, int $orders): array => ['date' => $date, 'orders' => $orders],
            array_keys($buckets),
            array_values($buckets),
        );
    }

    /**
     * @return array<string, int>
     */
    private function emptyDayBuckets(
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
        string $timezone,
    ): array {
        $buckets = [];
        $first = $utcFrom->setTimezone($timezone)->startOfDay();
        $last = $utcUntil->setTimezone($timezone)->startOfDay();

        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $buckets[$day->toDateString()] = 0;
        }

        return $buckets;
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     * @return list<array<string, mixed>>
     */
    private function orderStatusDistribution(array $statuses): array
    {
        return array_values(array_map(
            static fn (array $status): array => [
                'status' => $status['status'],
                'count' => (int) $status['orders'],
                'share_percent' => $status['share_percent'],
            ],
            array_filter($statuses, static fn (array $status): bool => (int) $status['orders'] > 0),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return list<array{date: string, revenue: float, orders: int}>
     */
    private function salesTrends(array $series): array
    {
        return array_map(
            static fn (array $day): array => [
                'date' => $day['date'],
                'revenue' => (float) ($day['recognized_order_value'] ?? 0),
                'orders' => (int) $day['orders'],
            ],
            $series,
        );
    }

    /**
     * Buyers are customers with at least one recognized order in the range.
     * Repeat buyers have at least two. Orders without a customer link are not
     * counted, because attributing them to a person would be a guess.
     *
     * @return array<string, mixed>
     */
    private function customerRepeatPurchaseRate(
        ?Store $store,
        string $currency,
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
    ): array {
        $rows = $this->recognizedOrders($store, $currency, $utcFrom, $utcUntil)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as order_count')
            ->groupBy('customer_id')
            ->get();

        $total = $rows->count();
        $repeat = $rows->filter(fn ($row): bool => (int) $row->order_count >= 2)->count();

        return [
            'total_customers_with_orders' => $total,
            'repeat_customers' => $repeat,
            'repeat_purchase_rate_percent' => $total > 0 ? round(($repeat / $total) * 100, 2) : 0.0,
            'measurable' => $total > 0,
        ];
    }

    private function recognizedOrders(
        ?Store $store,
        string $currency,
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
    ): Builder {
        return Order::query()
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [$utcFrom, $utcUntil])
            ->where('currency', $currency)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->whereNotIn('payment_status', [PaymentStatus::Refunded->value])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()));
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function productPerformance(array $products): array
    {
        return array_map(
            static fn (array $product): array => [
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'orders' => (int) $product['orders'],
                'units_sold' => (int) $product['units'],
                'revenue' => (float) $product['net_sales'],
                'net_sales' => (float) $product['net_sales'],
                'gross_profit' => $product['gross_profit'],
                'gross_margin_percent' => $product['gross_margin_percent'],
                'cost_complete' => (bool) $product['cost_complete'],
            ],
            $products,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $stores
     * @return list<array<string, mixed>>
     */
    private function storePerformance(array $stores): array
    {
        return array_map(
            static fn (array $store): array => [
                'store_id' => (int) $store['store_id'],
                'store' => $store['store'],
                'orders' => (int) $store['orders'],
                'revenue' => (float) $store['net_sales'],
                'average_order_value' => (float) $store['average_order_value'],
                'gross_profit' => $store['gross_profit'],
                'gross_margin_percent' => $store['gross_margin_percent'],
                'cost_complete' => (bool) $store['cost_complete'],
            ],
            $stores,
        );
    }

    /**
     * Current inventory snapshot. Stock is a present-state figure, not a
     * historical one, so it deliberately ignores the date range.
     *
     * @return array<string, mixed>
     */
    private function stockHealth(?Store $store): array
    {
        $inventories = Inventory::query()
            ->with(['product:id,name,sku', 'variant:id,name,sku', 'store:id,name'])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->get();

        $outOfStock = $inventories->filter(fn (Inventory $inventory): bool => $inventory->available() <= 0);
        $lowStock = $inventories->filter(fn (Inventory $inventory): bool => $inventory->available() > 0
            && $inventory->low_stock_threshold !== null
            && $inventory->available() <= $inventory->low_stock_threshold);

        $items = $inventories
            ->sortBy(fn (Inventory $inventory): int => $inventory->available())
            ->take(50)
            ->map(fn (Inventory $inventory): array => [
                'id' => (int) $inventory->getKey(),
                'store' => $inventory->store?->name,
                'product_name' => $inventory->product?->name ?? $inventory->variant?->name ?? 'Unknown product',
                'sku' => $inventory->variant?->sku ?? $inventory->product?->sku,
                'quantity' => (int) $inventory->quantity,
                'reserved_quantity' => (int) $inventory->reserved_quantity,
                'available' => $inventory->available(),
                'low_stock_threshold' => $inventory->low_stock_threshold,
                'status' => $this->stockStatus($inventory),
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'total_items' => $inventories->count(),
                'out_of_stock' => $outOfStock->count(),
                'low_stock' => $lowStock->count(),
                'healthy' => max(0, $inventories->count() - $outOfStock->count() - $lowStock->count()),
            ],
            'items' => $items,
            'insufficient_data' => $inventories->isEmpty(),
        ];
    }

    private function stockStatus(Inventory $inventory): string
    {
        if ($inventory->available() <= 0) {
            return 'out_of_stock';
        }

        if ($inventory->low_stock_threshold !== null && $inventory->available() <= $inventory->low_stock_threshold) {
            return 'low_stock';
        }

        return 'healthy';
    }

    /**
     * First-response time uses the first inbound message and the first
     * non-internal outbound message after it. Conversation dates are matched on
     * the conversation's creation time; message timestamps decide the response.
     *
     * @return array<string, mixed>
     */
    private function conversationResponseMetrics(
        ?Store $store,
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
    ): array {
        $conversations = Conversation::query()
            ->whereBetween('created_at', [$utcFrom, $utcUntil])
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->get(['id']);

        if ($conversations->isEmpty()) {
            return ['measurable' => false, 'reason' => 'no_conversations_in_range'];
        }

        $messages = ConversationMessage::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->orderByRaw('COALESCE(sent_at, created_at)')
            ->orderBy('id')
            ->get()
            ->groupBy('conversation_id');

        $responseTimes = [];

        foreach ($messages as $thread) {
            $inbound = $thread->first(fn (ConversationMessage $message): bool => $message->isInbound());

            if (! $inbound) {
                continue;
            }

            $inboundAt = $inbound->sent_at ?? $inbound->created_at;

            if ($inboundAt === null) {
                continue;
            }

            foreach ($thread as $message) {
                if ($message->isInbound() || $message->isInternal()) {
                    continue;
                }

                $outboundAt = $message->sent_at ?? $message->created_at;

                if ($outboundAt !== null && $outboundAt->greaterThanOrEqualTo($inboundAt)) {
                    $responseTimes[] = round($inboundAt->diffInMinutes($outboundAt), 2);
                    break;
                }
            }
        }

        $analyzed = $conversations->count();
        $answered = count($responseTimes);

        if ($answered === 0) {
            return [
                'measurable' => false,
                'reason' => 'no_response_timestamps',
                'conversations_analyzed' => $analyzed,
                'conversations_with_first_response' => 0,
            ];
        }

        return [
            'measurable' => true,
            'conversations_analyzed' => $analyzed,
            'conversations_with_first_response' => $answered,
            'response_rate_percent' => $analyzed > 0 ? round(($answered / $analyzed) * 100, 2) : 0.0,
            'avg_first_response_minutes' => round(array_sum($responseTimes) / $answered, 2),
            'median_first_response_minutes' => $this->median($responseTimes),
            'min_first_response_minutes' => min($responseTimes),
            'max_first_response_minutes' => max($responseTimes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesRecoveryFunnel(
        ?Store $store,
        string $currency,
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
    ): array {
        $base = RecoveryOpportunity::query()
            ->whereBetween('detected_at', [$utcFrom, $utcUntil])
            ->where('currency', $currency)
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()));

        $total = (clone $base)->count();
        $contacted = (clone $base)->where('contact_attempts', '>', 0)->count();
        $responded = (clone $base)->whereNotNull('responded_at')->count();
        $recovered = (clone $base)->where('status', RecoveryOpportunityStatus::Recovered->value)->count();
        $dismissed = (clone $base)->where('status', RecoveryOpportunityStatus::Dismissed->value)->count();
        $expired = (clone $base)->where('status', RecoveryOpportunityStatus::Expired->value)->count();

        $openStatuses = [
            RecoveryOpportunityStatus::Eligible->value,
            RecoveryOpportunityStatus::Contacted->value,
            RecoveryOpportunityStatus::Snoozed->value,
        ];

        $open = (clone $base)->whereIn('status', $openStatuses);
        $openCount = (clone $open)->count();
        $openPipeline = (float) (clone $open)->sum('estimated_value');

        $recoveredRevenue = (float) RecoveryOpportunity::query()
            ->join('orders', 'orders.id', '=', 'recovery_opportunities.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.currency', $currency)
            ->whereNotIn('orders.status', [OrderStatus::Cancelled->value])
            ->whereNotIn('orders.payment_status', [PaymentStatus::Refunded->value])
            ->whereBetween('recovery_opportunities.detected_at', [$utcFrom, $utcUntil])
            ->where('recovery_opportunities.currency', $currency)
            ->where('recovery_opportunities.status', RecoveryOpportunityStatus::Recovered->value)
            ->when($store, fn (Builder $query) => $query->where('recovery_opportunities.store_id', $store->getKey()))
            ->sum('orders.grand_total');

        return [
            'measurable' => $total > 0,
            'reason' => $total > 0 ? null : 'no_opportunities_in_range',
            'funnel' => [
                ['stage' => 'detected', 'count' => $total],
                ['stage' => 'contacted', 'count' => $contacted],
                ['stage' => 'responded', 'count' => $responded],
                ['stage' => 'recovered', 'count' => $recovered],
            ],
            'totals' => [
                'total_opportunities' => $total,
                'open_opportunities' => $openCount,
                'recovered' => $recovered,
                'dismissed' => $dismissed,
                'expired' => $expired,
            ],
            'open_pipeline_value' => round($openPipeline, 2),
            'recovered_revenue' => round($recoveredRevenue, 2),
            'conversion_rate_percent' => $total > 0 ? round(($recovered / $total) * 100, 2) : 0.0,
        ];
    }

    /**
     * Delivery statuses describe outbound provider messages. Internal notes and
     * inbound messages are excluded because they are never "delivered".
     *
     * @return array<string, mixed>
     */
    private function messageDeliveryMetrics(
        ?Store $store,
        CarbonImmutable $utcFrom,
        CarbonImmutable $utcUntil,
    ): array {
        $rows = ConversationMessage::query()
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->whereRaw('COALESCE(conversation_messages.sent_at, conversation_messages.created_at) BETWEEN ? AND ?', [$utcFrom, $utcUntil])
            ->where('conversation_messages.direction', MessageDirection::Outbound->value)
            ->where('conversation_messages.is_internal', false)
            ->when($store, fn (Builder $query) => $query->where('conversations.store_id', $store->getKey()))
            ->selectRaw('conversation_messages.delivery_status as status, COUNT(*) as count')
            ->groupBy('conversation_messages.delivery_status')
            ->get();

        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[(string) ($row->status ?? 'unknown')] = (int) $row->count;
        }

        $total = array_sum($byStatus);
        $deliveredOrRead = ($byStatus['delivered'] ?? 0) + ($byStatus['read'] ?? 0);
        $failed = $byStatus['failed'] ?? 0;

        return [
            'measurable' => $total > 0,
            'total_messages' => $total,
            'by_status' => $byStatus,
            'delivered_or_read' => $deliveredOrRead,
            'failed' => $failed,
            'delivery_rate_percent' => $total > 0 ? round(($deliveredOrRead / $total) * 100, 2) : null,
            'failure_rate_percent' => $total > 0 ? round(($failed / $total) * 100, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function financialMargin(array $summary): array
    {
        $measurable = (bool) $summary['cost_complete'] && $summary['gross_profit'] !== null;

        return [
            'measurable' => $measurable,
            'reason' => $measurable ? null : 'historical_cost_incomplete',
            'summary' => [
                'net_sales' => (float) $summary['net_sales'],
                'cogs' => (float) $summary['cogs'],
                'gross_profit' => $summary['gross_profit'] === null ? null : (float) $summary['gross_profit'],
                'gross_margin_percent' => $summary['gross_margin_percent'] === null
                    ? null
                    : (float) $summary['gross_margin_percent'],
                'cost_coverage_percent' => $summary['cost_coverage_percent'] === null
                    ? null
                    : (float) $summary['cost_coverage_percent'],
                'uncosted_units' => (int) $summary['uncosted_units'],
            ],
            'note' => 'Gross margin only. Net profit is not inferred because shipping and operating costs are not recorded.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(?Store $store, string $currency): array
    {
        $tenant = $this->tenants->required();

        return [
            'tenant' => (string) $tenant->name,
            'currency' => $currency,
            'timezone' => $this->timezone(),
            'store' => $store ? ['id' => (int) $store->getKey(), 'name' => (string) $store->name] : null,
        ];
    }

    private function timezone(): string
    {
        $tenant = $this->tenants->required();
        $timezone = (string) ($tenant->timezone ?: config('app.timezone', 'UTC'));

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
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
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }
}
