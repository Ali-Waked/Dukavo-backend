<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome of evaluating one rule for one business event.
 *
 * A skipped run is still recorded: it explains why a rule that appears active
 * did not act, which is usually a condition, cooldown or duplicate event.
 */
enum AutomationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Partial => 'Completed with errors',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
