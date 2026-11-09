<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationTrigger;
use App\Enums\OrderStatus;
use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;

/**
 * The single catalogue of supported automation triggers.
 *
 * A trigger only appears here when a business service genuinely emits it (or
 * the scheduler can evaluate it). Its `fields` list is the complete set of
 * values the event carries, so a condition can never reference data that does
 * not exist at execution time.
 */
final class AutomationTriggerRegistry
{
    /**
     * @return list<array{key: string, label: string, description: string, category: string, scheduled: bool, available: bool, availability_reason: string|null, fields: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return array_map(
            static fn (AutomationTrigger $trigger): array => self::describe($trigger),
            AutomationTrigger::cases(),
        );
    }

    /**
     * @return array{key: string, label: string, description: string, category: string, scheduled: bool, available: bool, availability_reason: string|null, fields: list<array<string, mixed>>}|null
     */
    public static function find(string $key): ?array
    {
        $trigger = AutomationTrigger::tryFrom($key);

        return $trigger instanceof AutomationTrigger ? self::describe($trigger) : null;
    }

    /**
     * @return array{key: string, label: string, description: string, category: string, scheduled: bool, available: bool, availability_reason: string|null, fields: list<array<string, mixed>>}
     */
    public static function describe(AutomationTrigger $trigger): array
    {
        return [
            'key' => $trigger->value,
            'label' => $trigger->label(),
            'description' => self::descriptionFor($trigger),
            'category' => $trigger->category(),
            'scheduled' => $trigger->isScheduled(),
            'available' => true,
            'availability_reason' => null,
            'fields' => self::fieldsFor($trigger),
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (AutomationTrigger $trigger): string => $trigger->value, AutomationTrigger::cases());
    }

    /**
     * @return list<string>
     */
    public static function fieldKeysFor(AutomationTrigger $trigger): array
    {
        return array_column(self::fieldsFor($trigger), 'key');
    }

    /**
     * A realistic payload used by the rule builder's preview and tests. It is
     * never dispatched as a real business event.
     *
     * @return array<string, scalar|null>
     */
    public static function sampleContext(AutomationTrigger $trigger): array
    {
        return match ($trigger) {
            AutomationTrigger::OrderCreated,
            AutomationTrigger::OrderPaid,
            AutomationTrigger::OrderDelivered,
            AutomationTrigger::OrderAbandoned => [
                'order_id' => 1001,
                'order_number' => 'ORD-01001',
                'status' => OrderStatus::Pending->value,
                'payment_status' => 'unpaid',
                'grand_total' => 149.9,
                'subtotal' => 139.9,
                'currency' => 'USD',
                'store_id' => 1,
                'customer_id' => 501,
                'customer_name' => 'Nadia Hassan',
                'customer_email' => 'nadia@example.com',
                'customer_phone' => '+970599000000',
                'item_count' => 2,
                'age_hours' => 24,
                'placed_at' => now()->subDay()->toIso8601String(),
            ],
            AutomationTrigger::OrderStatusChanged => [
                'order_id' => 1001,
                'order_number' => 'ORD-01001',
                'from_status' => OrderStatus::Pending->value,
                'to_status' => OrderStatus::Confirmed->value,
                'payment_status' => 'paid',
                'grand_total' => 149.9,
                'currency' => 'USD',
                'store_id' => 1,
                'customer_id' => 501,
                'customer_name' => 'Nadia Hassan',
                'customer_email' => 'nadia@example.com',
                'note' => '',
            ],
            AutomationTrigger::InventoryLowStock,
            AutomationTrigger::InventoryOutOfStock,
            AutomationTrigger::InventoryRestocked => [
                'inventory_id' => 301,
                'store_id' => 1,
                'store_name' => 'Main store',
                'product_id' => 41,
                'product_variant_id' => 42,
                'product_name' => 'Cotton shirt',
                'sku' => 'SHIRT-M',
                'quantity' => 3,
                'reserved_quantity' => 1,
                'available' => 2,
                'previous_available' => 0,
                'threshold' => 5,
            ],
            AutomationTrigger::ConversationMessageReceived => [
                'conversation_id' => 71,
                'message_id' => 9001,
                'customer_id' => 501,
                'customer_name' => 'Nadia Hassan',
                'channel' => 'whatsapp',
                'status' => 'open',
                'assigned_to' => 12,
                'preview' => 'Do you have this in medium?',
                'store_id' => 1,
            ],
            AutomationTrigger::ConversationAssigned => [
                'conversation_id' => 71,
                'customer_id' => 501,
                'customer_name' => 'Nadia Hassan',
                'channel' => 'whatsapp',
                'status' => 'open',
                'assigned_to' => 12,
                'assigned_name' => 'Samir Khalil',
                'previous_assignee_id' => null,
                'store_id' => 1,
            ],
            AutomationTrigger::RecoveryOpportunityDetected => [
                'opportunity_id' => 811,
                'reason' => RecoveryReason::AbandonedCart->value,
                'status' => RecoveryOpportunityStatus::Open->value,
                'estimated_value' => 220.0,
                'currency' => 'USD',
                'store_id' => 1,
                'customer_id' => 501,
                'customer_name' => 'Nadia Hassan',
                'customer_email' => 'nadia@example.com',
                'conversation_id' => 71,
                'cart_id' => 61,
                'assigned_to' => null,
            ],
            AutomationTrigger::CustomerCreated => [
                'customer_id' => 501,
                'customer_name' => 'Nadia Hassan',
                'customer_email' => 'nadia@example.com',
                'customer_phone' => '+970599000000',
                'store_id' => 1,
                'status' => 'active',
                'locale' => 'en',
            ],
            AutomationTrigger::ScheduleDaily => [
                'date' => now()->toDateString(),
                'weekday' => now()->englishDayOfWeek,
                'store_id' => 1,
                'store_name' => 'Main store',
            ],
            AutomationTrigger::ScheduleWeekly => [
                'week_start' => now()->startOfWeek()->toDateString(),
                'week_end' => now()->endOfWeek()->toDateString(),
                'iso_week' => (int) now()->isoWeek(),
                'year' => (int) now()->year,
                'store_id' => 1,
                'store_name' => 'Main store',
            ],
        };
    }

    private static function descriptionFor(AutomationTrigger $trigger): string
    {
        return match ($trigger) {
            AutomationTrigger::OrderCreated => 'Fires once after a new order commits.',
            AutomationTrigger::OrderStatusChanged => 'Fires whenever an order moves to a different status.',
            AutomationTrigger::OrderPaid => 'Fires when an order payment is confirmed.',
            AutomationTrigger::OrderDelivered => 'Fires when an order reaches the delivered status.',
            AutomationTrigger::OrderAbandoned => 'Scheduled check for unpaid or partially paid orders older than the configured age.',
            AutomationTrigger::InventoryLowStock => 'Fires when available stock reaches the configured low-stock threshold.',
            AutomationTrigger::InventoryOutOfStock => 'Fires when available stock reaches zero.',
            AutomationTrigger::InventoryRestocked => 'Fires when stock moves from zero or below the threshold back above it.',
            AutomationTrigger::ConversationMessageReceived => 'Fires after an inbound customer message is stored.',
            AutomationTrigger::ConversationAssigned => 'Fires when a conversation is assigned to a teammate.',
            AutomationTrigger::RecoveryOpportunityDetected => 'Fires when the recovery detector opens a lost-sale opportunity.',
            AutomationTrigger::CustomerCreated => 'Fires after a customer record is created.',
            AutomationTrigger::ScheduleDaily => 'Runs once per day for every workspace that has an active rule.',
            AutomationTrigger::ScheduleWeekly => 'Runs once per ISO week for every workspace that has an active rule.',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fieldsFor(AutomationTrigger $trigger): array
    {
        return match ($trigger) {
            AutomationTrigger::OrderCreated => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('status', 'Status', 'string', self::orderStatusOptions()),
                self::field('payment_status', 'Payment status', 'string'),
                self::field('grand_total', 'Order total', 'number'),
                self::field('subtotal', 'Subtotal', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
                self::field('customer_phone', 'Customer phone', 'string'),
                self::field('item_count', 'Item count', 'number'),
            ],
            AutomationTrigger::OrderStatusChanged => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('from_status', 'Previous status', 'string', self::orderStatusOptions()),
                self::field('to_status', 'New status', 'string', self::orderStatusOptions()),
                self::field('payment_status', 'Payment status', 'string'),
                self::field('grand_total', 'Order total', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
                self::field('note', 'Status note', 'string'),
            ],
            AutomationTrigger::OrderPaid => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('status', 'Order status', 'string', self::orderStatusOptions()),
                self::field('payment_status', 'Payment status', 'string'),
                self::field('grand_total', 'Order total', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
            ],
            AutomationTrigger::OrderDelivered => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('status', 'Status', 'string', self::orderStatusOptions()),
                self::field('payment_status', 'Payment status', 'string'),
                self::field('grand_total', 'Order total', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
                self::field('note', 'Delivery note', 'string'),
            ],
            AutomationTrigger::OrderAbandoned => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('status', 'Order status', 'string', self::orderStatusOptions()),
                self::field('payment_status', 'Payment status', 'string'),
                self::field('grand_total', 'Order total', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
                self::field('customer_phone', 'Customer phone', 'string'),
                self::field('age_hours', 'Hours since the order was placed', 'number'),
                self::field('placed_at', 'Placed at', 'string'),
            ],
            AutomationTrigger::InventoryLowStock,
            AutomationTrigger::InventoryOutOfStock,
            AutomationTrigger::InventoryRestocked => [
                self::field('inventory_id', 'Inventory ID', 'number'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('store_name', 'Store name', 'string'),
                self::field('product_id', 'Product ID', 'number'),
                self::field('product_variant_id', 'Variant ID', 'number'),
                self::field('product_name', 'Product name', 'string'),
                self::field('sku', 'SKU', 'string'),
                self::field('quantity', 'On hand', 'number'),
                self::field('reserved_quantity', 'Reserved', 'number'),
                self::field('available', 'Available', 'number'),
                self::field('previous_available', 'Previous available', 'number'),
                self::field('threshold', 'Low-stock threshold', 'number'),
            ],
            AutomationTrigger::ConversationMessageReceived => [
                self::field('conversation_id', 'Conversation ID', 'number'),
                self::field('message_id', 'Message ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('channel', 'Channel', 'string'),
                self::field('status', 'Conversation status', 'string'),
                self::field('assigned_to', 'Assigned user ID', 'number'),
                self::field('preview', 'Message preview', 'string'),
                self::field('store_id', 'Store ID', 'number'),
            ],
            AutomationTrigger::ConversationAssigned => [
                self::field('conversation_id', 'Conversation ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('channel', 'Channel', 'string'),
                self::field('status', 'Conversation status', 'string'),
                self::field('assigned_to', 'Assigned user ID', 'number'),
                self::field('assigned_name', 'Assigned teammate', 'string'),
                self::field('previous_assignee_id', 'Previous assignee ID', 'number'),
                self::field('store_id', 'Store ID', 'number'),
            ],
            AutomationTrigger::RecoveryOpportunityDetected => [
                self::field('opportunity_id', 'Opportunity ID', 'number'),
                self::field('reason', 'Reason', 'string', self::recoveryReasonOptions()),
                self::field('status', 'Status', 'string', self::recoveryStatusOptions()),
                self::field('estimated_value', 'Estimated value', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
                self::field('conversation_id', 'Conversation ID', 'number'),
                self::field('cart_id', 'Cart ID', 'number'),
                self::field('assigned_to', 'Assigned user ID', 'number'),
            ],
            AutomationTrigger::CustomerCreated => [
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('customer_email', 'Customer email', 'string'),
                self::field('customer_phone', 'Customer phone', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('status', 'Status', 'string'),
                self::field('locale', 'Locale', 'string'),
            ],
            AutomationTrigger::ScheduleDaily => [
                self::field('date', 'Date', 'string'),
                self::field('weekday', 'Weekday', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('store_name', 'Store name', 'string'),
            ],
            AutomationTrigger::ScheduleWeekly => [
                self::field('week_start', 'Week start', 'string'),
                self::field('week_end', 'Week end', 'string'),
                self::field('iso_week', 'ISO week', 'number'),
                self::field('year', 'Year', 'number'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('store_name', 'Store name', 'string'),
            ],
        };
    }

    /**
     * @param  list<array{value: string, label: string}>|null  $options
     * @return array<string, mixed>
     */
    private static function field(string $key, string $label, string $type, ?array $options = null): array
    {
        $field = ['key' => $key, 'label' => $label, 'type' => $type];

        if ($options !== null) {
            $field['options'] = $options;
        }

        return $field;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function orderStatusOptions(): array
    {
        return array_map(static fn (OrderStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], OrderStatus::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function recoveryReasonOptions(): array
    {
        return array_map(static fn (RecoveryReason $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
        ], RecoveryReason::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function recoveryStatusOptions(): array
    {
        return array_map(static fn (RecoveryOpportunityStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], RecoveryOpportunityStatus::cases());
    }
}
