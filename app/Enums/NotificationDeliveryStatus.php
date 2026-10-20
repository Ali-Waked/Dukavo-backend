<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of one outbound delivery attempt.
 *
 * The states are chosen so that a claim is only ever made when there is
 * evidence for it: `sent` means a provider accepted the message, `delivered`
 * is reserved for a provider receipt that says so, and `skipped` records a
 * decision *not* to send (an unconfigured channel, missing destination or a
 * disabled preference) without pretending a message went anywhere.
 */
enum NotificationDeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * A delivery in one of these states will not change again without operator
     * action, so the retry machinery leaves it alone.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Skipped, self::Cancelled], true);
    }

    /**
     * States a failed delivery may be safely retried from.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
