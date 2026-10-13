<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Triage state of an inbound contact message.
 *
 * Every message starts at `New`; the platform team moves it through `Read`,
 * `Replied` and `Closed` from the admin inbox.
 */
enum ContactStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Replied = 'replied';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Read => 'Read',
            self::Replied => 'Replied',
            self::Closed => 'Closed',
        };
    }
}
