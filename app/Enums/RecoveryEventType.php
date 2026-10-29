<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The auditable funnel for one recovery opportunity.
 *
 * Contacted is recorded only after the provider accepts the message, so the
 * timeline never claims an outreach that did not leave Dukavo.
 */
enum RecoveryEventType: string
{
    case Detected = 'detected';
    case Assigned = 'assigned';
    case Contacted = 'contacted';
    case Responded = 'responded';
    case Snoozed = 'snoozed';
    case Reopened = 'reopened';
    case Dismissed = 'dismissed';
    case Recovered = 'recovered';
    case AttributionReleased = 'attribution_released';
    case Expired = 'expired';
    case OptedOut = 'opted_out';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Detected',
            self::Assigned => 'Assigned',
            self::Contacted => 'Follow-up sent',
            self::Responded => 'Customer replied',
            self::Snoozed => 'Snoozed',
            self::Reopened => 'Reopened',
            self::Dismissed => 'Dismissed',
            self::Recovered => 'Order recovered',
            self::AttributionReleased => 'Attribution released',
            self::Expired => 'Expired',
            self::OptedOut => 'Do not contact',
        };
    }
}
