<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationTaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Completed => 'Completed',
            self::Dismissed => 'Dismissed',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }
}
