<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The closed lifecycle of a lost-sale opportunity.
 *
 * A recovered opportunity is always attributed to a real order; the status is
 * never set by hand with an invented value. Snoozing hides the row until its
 * follow-up time, while dismissal records a human decision to stop working it.
 */
enum RecoveryOpportunityStatus: string
{
    case Eligible = 'eligible';
    case Contacted = 'contacted';
    case Snoozed = 'snoozed';
    case Recovered = 'recovered';
    case Dismissed = 'dismissed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Ready to recover',
            self::Contacted => 'Follow-up sent',
            self::Snoozed => 'Snoozed',
            self::Recovered => 'Recovered',
            self::Dismissed => 'Dismissed',
            self::Expired => 'Expired',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Eligible, self::Contacted, self::Snoozed], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Recovered, self::Dismissed, self::Expired], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Eligible => [self::Contacted, self::Snoozed, self::Dismissed, self::Recovered, self::Expired],
            self::Contacted => [self::Snoozed, self::Dismissed, self::Recovered, self::Expired],
            self::Snoozed => [self::Eligible, self::Contacted, self::Dismissed, self::Recovered, self::Expired],
            self::Recovered, self::Dismissed, self::Expired => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
