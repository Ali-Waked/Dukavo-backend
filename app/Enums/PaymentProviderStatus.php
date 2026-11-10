<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a provider may be offered to merchants at all.
 *
 * `integration_enabled` is intentionally a separate database flag: a provider
 * can be catalogued (and its country coverage documented) while its adapter is
 * still awaiting commercial approval.
 */
enum PaymentProviderStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending approval',
            self::Inactive => 'Inactive',
        };
    }
}
