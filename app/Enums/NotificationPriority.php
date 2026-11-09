<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How urgently a notification should be handled.
 *
 * Priority is independent of severity: severity describes the tone of the
 * message, priority describes how quickly a person must act. Push transports
 * use it to choose interruption level and sound; the in-app list uses it for
 * ordering emphasis.
 */
enum NotificationPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isUrgent(): bool
    {
        return in_array($this, [self::High, self::Critical], true);
    }
}
