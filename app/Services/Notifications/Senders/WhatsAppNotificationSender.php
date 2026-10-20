<?php

declare(strict_types=1);

namespace App\Services\Notifications\Senders;

use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\ChannelAvailability;
use App\Services\Notifications\Contracts\NotificationSender;

/**
 * Delivers a notification by WhatsApp.
 *
 * The conversations module already sends messages inside a thread, but a
 * business-initiated notification is a different path: the provider requires an
 * approved template bound to the recipient's phone number, and that transport
 * does not exist yet. Until it does, {@see ChannelAvailability} reports the
 * channel unavailable and this sender records `skipped` with the reason rather
 * than claiming a send. When the transport lands, this is the one place that
 * changes: build the template payload, call the provider, then `markSent()` with
 * the returned message id.
 */
final class WhatsAppNotificationSender implements NotificationSender
{
    public function __construct(private readonly ChannelAvailability $availability) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::WhatsApp;
    }

    public function send(NotificationDelivery $delivery): void
    {
        $reason = $this->availability->reason($this->channel());

        if ($reason !== null) {
            $delivery->markSkipped($reason);

            return;
        }

        $recipient = $delivery->recipient_type === 'user'
            ? User::query()->find($delivery->recipient_id)
            : null;

        if (! $recipient instanceof User || blank($recipient->phone)) {
            $delivery->markSkipped('phone_missing');

            return;
        }

        // Unreachable while the channel reports unavailable above; the guard
        // keeps a future transport from ever claiming success on its own.
        $delivery->markSkipped('whatsapp_transport_unavailable');
    }
}
