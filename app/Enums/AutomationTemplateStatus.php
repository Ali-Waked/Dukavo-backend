<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function isInstallable(): bool
    {
        return $this === self::Active;
    }
}
