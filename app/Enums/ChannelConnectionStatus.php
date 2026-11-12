<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a tenant's connection to a communication provider.
 *
 * `pending` means the merchant started onboarding but the provider has not
 * confirmed the credentials yet. `error` keeps the connection visible so the
 * merchant can repair it instead of silently losing the channel.
 */
enum ChannelConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';

    public function isUsable(): bool
    {
        return $this === self::Connected;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting verification',
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::Error => 'Needs attention',
        };
    }
}
