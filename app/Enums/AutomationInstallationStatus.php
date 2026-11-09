<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationInstallationStatus: string
{
    case Installed = 'installed';
    case Disabled = 'disabled';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Installed => 'Installed',
            self::Disabled => 'Disabled',
            self::Removed => 'Removed',
        };
    }
}
