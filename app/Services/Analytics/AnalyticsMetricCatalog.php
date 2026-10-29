<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Central registry of analytics metric definitions.
 *
 * Every metric has a clear meaning, data source and known limitation. Revenue
 * and profit are never conflated, and a metric that the stored data cannot
 * support is withheld rather than estimated.
 */
final class AnalyticsMetricCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'kpis' => [
                'name' => 'Headline metrics',
                'description' => 'Revenue, recognized orders, total orders, average order value and units sold for the selected range, each compared with the preceding equal-length range.',
                'unit' => 'mixed',
                'source' => 'orders, order_items',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Revenue is the sum of recognized order totals, not profit. Cancelled and refunded orders are excluded from recognized figures but remain in total orders.',
            ],
            'orders_over_time' => [
                'name' => 'Orders over time',
                'description' => 'Count of all placed orders per calendar day in the selected range.',
                'unit' => 'count',
                'source' => 'orders',
                'filters' => ['tenant_id', 'store_id', 'date_range', 'timezone'],
                'notes' => 'Days with no orders are present as zeroes. Includes cancelled and refunded orders; use recognized_orders for sales volume.',
            ],
            'order_status_distribution' => [
                'name' => 'Order status distribution',
                'description' => 'Distribution of orders placed in the range by current status.',
                'unit' => 'count',
                'source' => 'orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Status is the current status, not a historical snapshot. Only statuses with at least one order are returned.',
            ],
            'sales_trends' => [
                'name' => 'Sales trends',
                'description' => 'Recognized revenue and recognized order count per calendar day.',
                'unit' => 'currency/count',
                'source' => 'orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Recognized revenue excludes cancelled and refunded orders. Revenue is not profit.',
            ],
            'customer_repeat_purchase_rate' => [
                'name' => 'Customer repeat purchase rate',
                'description' => 'Share of identified buyers with at least two recognized orders in the range.',
                'unit' => 'percent',
                'source' => 'orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range'],
                'notes' => 'Only orders linked to a customer are counted; guest orders and cancelled or refunded orders are excluded.',
            ],
            'product_performance' => [
                'name' => 'Product performance',
                'description' => 'Orders, units sold, net sales and gross profit by product from recognized order lines.',
                'unit' => 'mixed',
                'source' => 'order_items, orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Net sales are unit price times quantity minus discounts. Gross profit is withheld for a product when any of its lines lacks a complete cost snapshot.',
            ],
            'store_performance' => [
                'name' => 'Store performance',
                'description' => 'Recognized orders, net sales, average order value and gross profit by store.',
                'unit' => 'mixed',
                'source' => 'orders, order_items, stores',
                'filters' => ['tenant_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Only stores with recognized orders in range appear. Gross profit is withheld when cost data is incomplete.',
            ],
            'stock_health' => [
                'name' => 'Stock health',
                'description' => 'Current inventory snapshot with on-hand, reserved, available, low-stock and out-of-stock counts.',
                'unit' => 'count',
                'source' => 'inventories, products, product_variants, stores',
                'filters' => ['tenant_id', 'store_id'],
                'notes' => 'Present-state metric: the date range does not apply. Low stock means available quantity is above zero and at or below the stored threshold.',
            ],
            'conversation_response_metrics' => [
                'name' => 'Conversation response metrics',
                'description' => 'Share of conversations that received a reply and the average, median, minimum and maximum minutes to first response.',
                'unit' => 'mixed',
                'source' => 'conversations, conversation_messages',
                'filters' => ['tenant_id', 'store_id', 'date_range'],
                'notes' => 'Uses the first inbound message and the first non-internal outbound message after it. Conversations without usable timestamps are reported as insufficient data, never guessed.',
            ],
            'sales_recovery_funnel' => [
                'name' => 'Sales recovery funnel',
                'description' => 'Detected, contacted, responded and recovered opportunity counts, open pipeline value and recovered revenue.',
                'unit' => 'mixed',
                'source' => 'recovery_opportunities, orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range'],
                'notes' => 'Recovered revenue is the linked, recognized order total. Open pipeline uses the stored estimated value and is not a forecast.',
            ],
            'message_delivery_metrics' => [
                'name' => 'Message delivery metrics',
                'description' => 'Delivery status counts and rates for outbound conversation messages.',
                'unit' => 'count/percent',
                'source' => 'conversation_messages, conversations',
                'filters' => ['tenant_id', 'store_id', 'date_range'],
                'notes' => 'Inbound messages and internal notes are excluded. Unknown or missing provider statuses are grouped as unknown.',
            ],
            'financial_margin' => [
                'name' => 'Financial margin',
                'description' => 'Gross margin from recognized order lines where complete historical cost snapshots exist.',
                'unit' => 'currency/percent',
                'source' => 'orders, order_items',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Gross margin only. Net profit is never inferred because shipping and operating costs are not recorded.',
            ],
            'payment_summary' => [
                'name' => 'Payment summary',
                'description' => 'Order values grouped by stored payment status, including unpaid and partially paid orders.',
                'unit' => 'mixed',
                'source' => 'orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Partial payment amounts and cash collected are not stored, so outstanding cash is withheld when partial payments exist.',
            ],
            'refunds' => [
                'name' => 'Refunds',
                'description' => 'Count and value of fully refunded orders in range.',
                'unit' => 'mixed',
                'source' => 'orders',
                'filters' => ['tenant_id', 'store_id', 'currency', 'date_range', 'timezone'],
                'notes' => 'Refund amounts are not stored separately; a refunded order is excluded from recognized revenue in full rather than estimated.',
            ],
        ];
    }
}
