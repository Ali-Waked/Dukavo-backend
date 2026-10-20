<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;

/**
 * Whether a channel can actually be sent on right now.
 *
 * This is the seam between "the product supports WhatsApp" and "this
 * installation can send a WhatsApp message". The conversations module already
 * sends replies inside a thread, but a business-initiated notification needs an
 * approved template and a destination-bound transport, neither of which exists
 * yet. Until they do, the channel reports unavailable and deliveries are
 * recorded as skipped rather than pretending a message was accepted.
 */
final class ChannelAvailability
{
    public function isAvailable(NotificationChannel $channel): bool
    {
        return $this->reason($channel) === null;
    }

    /**
     * The reason a channel cannot be used, or null when it can.
     */
    public function reason(NotificationChannel $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::Mail => config('dukavo.notifications.channels.mail.enabled', true)
                ? null
                : 'mail_disabled',
            NotificationChannel::WhatsApp => $this->whatsappReason(),
        };
    }

    /**
     * Channel state for the settings screen, keyed by the stored preference
     * key so the client can disable a switch and explain why.
     *
     * @return array<string, array{available: bool, reason: string|null, label: string}>
     */
    public function summary(): array
    {
        $summary = [];

        foreach (NotificationChannel::cases() as $channel) {
            $summary[$channel->preferenceKey()] = [
                'available' => $this->isAvailable($channel),
                'reason' => $this->reason($channel),
                'label' => $channel->label(),
            ];
        }

        return $summary;
    }

    private function whatsappReason(): ?string
    {
        if (! config('dukavo.notifications.channels.whatsapp.enabled', false)) {
            return 'whatsapp_not_configured';
        }

        if (blank(config('dukavo.notifications.channels.whatsapp.template'))) {
            // The provider will reject a business-initiated message with no
            // approved template, so enabling the flag alone is not enough.
            return 'whatsapp_template_missing';
        }

        // A template is configured, but no transport binds it to a recipient's
        // phone number yet. Reporting it unavailable keeps the promise honest.
        return 'whatsapp_transport_unavailable';
    }
}
