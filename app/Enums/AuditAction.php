<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Audit actions are intentionally a closed set: an audit log is only useful
 * when it can be queried with predictable semantics.
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Login = 'login';
    case Logout = 'logout';
    case RoleChanged = 'role_changed';
    case PermissionChanged = 'permission_changed';
    case OrderStatusChanged = 'order_status_changed';
    case InventoryAdjusted = 'inventory_adjusted';
    case Restored = 'restored';
    case AiActionProposed = 'ai_action_proposed';
    case AiActionSubmitted = 'ai_action_submitted';
    case AiActionApproved = 'ai_action_approved';
    case AiActionRejected = 'ai_action_rejected';
    case AiActionExecuted = 'ai_action_executed';
    case AiActionFailed = 'ai_action_failed';
    case AiActionExpired = 'ai_action_expired';
    case ConversationAssigned = 'conversation_assigned';
    case ConversationStatusChanged = 'conversation_status_changed';
    case ConversationMessageSent = 'conversation_message_sent';
    case ConversationNoteAdded = 'conversation_note_added';
    case NotificationCampaignCreated = 'notification_campaign_created';
    case NotificationCampaignUpdated = 'notification_campaign_updated';
    case NotificationCampaignPublished = 'notification_campaign_published';
    case NotificationCampaignCancelled = 'notification_campaign_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Login => 'Logged in',
            self::Logout => 'Logged out',
            self::RoleChanged => 'Role changed',
            self::PermissionChanged => 'Permission changed',
            self::OrderStatusChanged => 'Order status changed',
            self::InventoryAdjusted => 'Inventory adjusted',
            self::Restored => 'Restored',
            self::AiActionProposed => 'AI action proposed',
            self::AiActionSubmitted => 'AI action submitted',
            self::AiActionApproved => 'AI action approved',
            self::AiActionRejected => 'AI action rejected',
            self::AiActionExecuted => 'AI action executed',
            self::AiActionFailed => 'AI action failed',
            self::AiActionExpired => 'AI action expired',
            self::ConversationAssigned => 'Conversation assigned',
            self::ConversationStatusChanged => 'Conversation status changed',
            self::ConversationMessageSent => 'Conversation message sent',
            self::ConversationNoteAdded => 'Conversation note added',
            self::NotificationCampaignCreated => 'Notification campaign created',
            self::NotificationCampaignUpdated => 'Notification campaign updated',
            self::NotificationCampaignPublished => 'Notification campaign published',
            self::NotificationCampaignCancelled => 'Notification campaign cancelled',
        };
    }
}
