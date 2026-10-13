<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a newsletter subscriber.
 *
 * A `Subscribed` row carries a `consented_at` timestamp written when the
 * address joined — the act of subscribing is the consent; `Unsubscribed`
 * rows keep the email and its original consent on record while stopping the
 * mail stream. Repeated unsubscribes are idempotent, and an address that
 * re-subscribes after unsubscribing records fresh consent.
 */
enum NewsletterStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';

    public function label(): string
    {
        return match ($this) {
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed',
        };
    }
}
