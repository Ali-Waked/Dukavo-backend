<?php

declare(strict_types=1);

namespace App\Services\Notifications\Senders;

use App\Enums\NotificationChannel;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\ChannelAvailability;
use App\Services\Notifications\Contracts\NotificationSender;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a notification by email.
 *
 * A delivery is only marked `sent` after the mailer accepted the message. The
 * checks here are a last line of defence: the dispatcher already declines to
 * queue for an unconfigured channel, a missing address or an unverified account,
 * but a value can change between queueing and sending, so the sender re-checks
 * rather than trusting the row.
 */
final class EmailNotificationSender implements NotificationSender
{
    public function __construct(private readonly ChannelAvailability $availability) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Mail;
    }

    public function send(NotificationDelivery $delivery): void
    {
        if (! $this->availability->isAvailable($this->channel())) {
            $delivery->markSkipped($this->availability->reason($this->channel()) ?? 'channel_disabled');

            return;
        }

        $recipient = $this->recipient($delivery);

        if (! $recipient instanceof User) {
            $delivery->markSkipped('recipient_missing');

            return;
        }

        if (blank($recipient->email)) {
            $delivery->markSkipped('email_missing');

            return;
        }

        if (config('dukavo.notifications.require_verified_email', true) && $recipient->email_verified_at === null) {
            $delivery->markSkipped('email_unverified');

            return;
        }

        $notification = $delivery->notification;

        if (! $notification instanceof Notification) {
            $delivery->markSkipped('notification_missing');

            return;
        }

        Mail::to($recipient->email)->send(new NotificationMail($notification, $recipient));

        $delivery->markSent();
    }

    private function recipient(NotificationDelivery $delivery): ?User
    {
        if ($delivery->recipient_type !== 'user') {
            return null;
        }

        return User::query()->find($delivery->recipient_id);
    }
}
