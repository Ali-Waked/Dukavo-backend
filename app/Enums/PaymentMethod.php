<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a customer intends to pay.
 *
 * The enum is deliberately small: only methods the platform can actually honour
 * are cases. Cash on delivery is a real offline agreement recorded as unpaid.
 * `online` exists as a boundary for a future provider but is never advertised as
 * available until that provider is configured (see StorefrontPaymentService).
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on delivery',
            self::Online => 'Pay online',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cod => 'Pay the courier in cash when your order arrives.',
            self::Online => 'Pay with a card or wallet through the payment provider.',
        };
    }
}
