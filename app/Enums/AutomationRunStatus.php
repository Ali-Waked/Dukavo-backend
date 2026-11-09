<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome of evaluating one rule for one business event.
 *
 * A skipped run is still recorded: it explains why a rule that appears active
 * did not act, which is usually a condition, cooldown, duplicate event or an
 * unavailable integration.
 */
enum AutomationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Retrying = 'retrying';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Partial => 'Completed with errors',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Retrying => 'Waiting to retry',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed, self::Skipped], true);
    }
}
