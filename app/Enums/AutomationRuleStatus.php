<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an automation rule may react to new events.
 *
 * Rules start paused when created through the editor: activating a rule is a
 * deliberate second decision, not an accident caused by saving a draft.
 */
enum AutomationRuleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
