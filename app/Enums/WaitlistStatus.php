<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a marketing site waitlist entry.
 *
 * A `Pending` row is a raw signup; `Invited` means Dukavo offered access;
 * `Joined` means the person converted into a real account; `Disqualified`
 * suppresses a duplicate or abusive address without deleting the record.
 */
enum WaitlistStatus: string
{
    case Pending = 'pending';
    case Invited = 'invited';
    case Joined = 'joined';
    case Disqualified = 'disqualified';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Invited => 'Invited',
            self::Joined => 'Joined',
            self::Disqualified => 'Disqualified',
        };
    }
}
