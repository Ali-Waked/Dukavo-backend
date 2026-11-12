<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Coarse health of a provider connection, refreshed by test/health checks and
 * by the outcome of real sends. It is deliberately separate from the
 * connection status: a connected account can be unhealthy.
 */
enum ChannelHealth: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Failing => 'Failing',
        };
    }
}
