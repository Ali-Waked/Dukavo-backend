<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one online payment attempt.
 *
 * A retry creates a new attempt rather than mutating a failed one, so the
 * financial trail always shows what was asked of the provider and what it
 * answered. Only `succeeded` may mark an order paid.
 */
enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::RequiresAction => 'Awaiting customer action',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Expired], true);
    }

    public function isSucceeded(): bool
    {
        return $this === self::Succeeded;
    }

    public function allowsRetry(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired], true);
    }
}
