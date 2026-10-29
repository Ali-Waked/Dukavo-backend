<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an opportunity was detected.
 *
 * These are deliberately conservative signals, not "any quiet conversation".
 * A cart must still exist with real lines, or the customer's last message must
 * be an unanswered inbound message. That keeps an idle thread from being
 * labelled a lost sale.
 */
enum RecoveryReason: string
{
    case AbandonedCart = 'abandoned_cart';
    case UnconfirmedQuote = 'unconfirmed_quote';
    case UnansweredInquiry = 'unanswered_inquiry';

    public function label(): string
    {
        return match ($this) {
            self::AbandonedCart => 'Cart left unfinished',
            self::UnconfirmedQuote => 'Quote not confirmed',
            self::UnansweredInquiry => 'Customer question unanswered',
        };
    }
}
