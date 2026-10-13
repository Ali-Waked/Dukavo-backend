<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Invited => 'Invited',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Suspended users keep their data but may no longer authenticate.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active || $this === self::Invited;
    }
}
