<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A transport a notification may leave the platform on.
 *
 * In-app delivery is deliberately *not* a channel here: the in-app copy is the
 * `notifications` row itself, always written, and therefore has no delivery
 * attempt to track. Only outbound transports appear, and only those a provider
 * can actually accept.
 */
enum NotificationChannel: string
{
    case Mail = 'mail';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /**
     * The switch key this channel is stored under in a user's preferences.
     *
     * `mail` is the transport's own vocabulary; the account settings screen has
     * always called it "email", and that stored key must not change silently.
     */
    public function preferenceKey(): string
    {
        return match ($this) {
            self::Mail => 'email',
            self::WhatsApp => 'whatsapp',
        };
    }

    /**
     * The queue a delivery for this channel is processed on, so a slow provider
     * cannot starve the other channel's jobs.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Mail => 'notifications-mail',
            self::WhatsApp => 'notifications-whatsapp',
        };
    }
}
