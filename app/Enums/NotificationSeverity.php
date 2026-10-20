<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How loudly a notification should present itself.
 *
 * The whole catalogue is graded, which is what keeps the centre from making
 * every event look urgent: an order arriving is `Info`, a stock-out is
 * `Warning`, and only a genuine service or security failure is `Critical`.
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Success => 'Success',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    /**
     * Critical notifications are the only ones a policy may force through every
     * configured channel regardless of a user's opt-outs.
     */
    public function isCritical(): bool
    {
        return $this === self::Critical;
    }
}
