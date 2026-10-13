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
        };
    }
}
