<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Payments\PaymentEligibilityService;

/**
 * How a customer intends to pay.
 *
 * The enum distinguishes the order-level intent from the specific method a
 * provider exposes (card, mada, KNet, Benefit, QPay, OmanNet, …). An `online`
 * order is only advertised once a compatible provider connection is configured
 * and verified; see {@see PaymentEligibilityService}.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
    case BankTransfer = 'bank_transfer';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on delivery',
            self::BankTransfer => 'Bank transfer',
            self::Online => 'Pay online',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cod => 'Pay the courier in cash when your order arrives.',
            self::BankTransfer => 'Transfer the amount to the store and send the receipt.',
            self::Online => 'Pay with a card or local payment method through the payment provider.',
        };
    }

    public function requiresImmediatePayment(): bool
    {
        return $this === self::Online;
    }
}
