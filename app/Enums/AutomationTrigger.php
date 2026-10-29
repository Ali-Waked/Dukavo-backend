<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Business events an automation rule may subscribe to.
 *
 * Every trigger is emitted after the underlying business transaction commits.
 * That ordering is what stops an automation from acting on an order or stock
 * movement that was subsequently rolled back.
 */
enum AutomationTrigger: string
{
    case OrderCreated = 'order.created';
    case OrderStatusChanged = 'order.status_changed';
    case InventoryLowStock = 'inventory.low_stock';
    case ConversationMessageReceived = 'conversation.message_received';
    case RecoveryOpportunityDetected = 'recovery.opportunity_detected';

    public function label(): string
    {
        return match ($this) {
            self::OrderCreated => 'Order created',
            self::OrderStatusChanged => 'Order status changed',
            self::InventoryLowStock => 'Inventory reached its low-stock threshold',
            self::ConversationMessageReceived => 'Customer message received',
            self::RecoveryOpportunityDetected => 'Recovery opportunity detected',
        };
    }
}
