<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Business events an automation rule may subscribe to.
 *
 * Every event trigger is emitted after the underlying business transaction
 * commits. Scheduled triggers (`schedule.*` and `order.abandoned`) are produced
 * by the automation scheduler, which runs inside each tenant's context.
 */
enum AutomationTrigger: string
{
    case OrderCreated = 'order.created';
    case OrderStatusChanged = 'order.status_changed';
    case OrderPaid = 'order.paid';
    case OrderDelivered = 'order.delivered';
    case OrderAbandoned = 'order.abandoned';
    case InventoryLowStock = 'inventory.low_stock';
    case InventoryOutOfStock = 'inventory.out_of_stock';
    case InventoryRestocked = 'inventory.restocked';
    case ConversationMessageReceived = 'conversation.message_received';
    case ConversationAssigned = 'conversation.assigned';
    case RecoveryOpportunityDetected = 'recovery.opportunity_detected';
    case CustomerCreated = 'customer.created';
    case ScheduleDaily = 'schedule.daily';
    case ScheduleWeekly = 'schedule.weekly';

    public function label(): string
    {
        return match ($this) {
            self::OrderCreated => 'Order created',
            self::OrderStatusChanged => 'Order status changed',
            self::OrderPaid => 'Order payment confirmed',
            self::OrderDelivered => 'Order delivered',
            self::OrderAbandoned => 'Unpaid order follow-up is due',
            self::InventoryLowStock => 'Inventory reached its low-stock threshold',
            self::InventoryOutOfStock => 'Inventory is out of stock',
            self::InventoryRestocked => 'Inventory is back above its threshold',
            self::ConversationMessageReceived => 'Customer message received',
            self::ConversationAssigned => 'Conversation assigned',
            self::RecoveryOpportunityDetected => 'Recovery opportunity detected',
            self::CustomerCreated => 'Customer created',
            self::ScheduleDaily => 'Every day',
            self::ScheduleWeekly => 'Every week',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::OrderCreated,
            self::OrderStatusChanged,
            self::OrderPaid,
            self::OrderDelivered,
            self::OrderAbandoned => 'orders',
            self::InventoryLowStock,
            self::InventoryOutOfStock,
            self::InventoryRestocked => 'inventory',
            self::ConversationMessageReceived,
            self::ConversationAssigned => 'conversations',
            self::RecoveryOpportunityDetected => 'recovery',
            self::CustomerCreated => 'customers',
            self::ScheduleDaily,
            self::ScheduleWeekly => 'scheduled',
        };
    }

    public function isScheduled(): bool
    {
        return in_array($this, [
            self::OrderAbandoned,
            self::ScheduleDaily,
            self::ScheduleWeekly,
        ], true);
    }

    /**
     * Scheduled triggers evaluate without a single business event, so they
     * need a date bucket to keep the run idempotent for the whole period.
     */
    public function scheduleFrequency(): ?string
    {
        return match ($this) {
            self::OrderAbandoned,
            self::ScheduleDaily => 'daily',
            self::ScheduleWeekly => 'weekly',
            default => null,
        };
    }
}
