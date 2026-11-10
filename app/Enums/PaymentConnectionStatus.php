<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A merchant's relationship with one payment provider for one store.
 *
 * Credentials may be stored while a connection is pending, but no online
 * method is advertised until the provider connection test has succeeded and the
 * row is `active`.
 */
enum PaymentConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Revoked => 'Revoked',
        };
    }
}
