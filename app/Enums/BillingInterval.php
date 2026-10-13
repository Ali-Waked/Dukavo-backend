<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a pricing plan bills.
 *
 * `OneTime` exists for future flat-fee offerings; `Monthly` and `Yearly`
 * cover the recurring plans the marketing site renders today.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
            self::OneTime => 'One time',
        };
    }
}
