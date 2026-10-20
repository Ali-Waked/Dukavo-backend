<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whose world a notification belongs to.
 *
 * This is the isolation axis for the whole subsystem: a platform notification
 * concerns Dukavo itself and carries no tenant, a tenant notification is only
 * ever readable inside the tenant that produced it, and a customer
 * notification is an outbound message to a contact rather than an entry in any
 * user's in-app list.
 */
enum NotificationScope: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Tenant => 'Tenant',
            self::Customer => 'Customer',
        };
    }
}
