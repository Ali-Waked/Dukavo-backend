<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationWhatsApp;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEventKey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for raising a notification.
 *
 * It is called directly by the service that just changed business state, after
 * that service's own transaction has committed, so a notification is only ever
 * produced for work that actually happened. Dispatch is idempotent: a
 * per-recipient ledger row ({@see NotificationEventKey}) refuses a second
 * notification for a one-shot event and enforces a cooldown for a recurring one
 * such as a low-stock alert.
 *
 * Delivery and transport are deliberately separate steps. The notification and
 * its delivery rows are written in one transaction; only once that commits are
 * the send jobs dispatched, so a rolled-back notification can never have
 * produced an email, and the in-app record is never coupled to a send result.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationEventCatalog $catalog,
        private readonly NotificationRecipientResolver $resolver,
        private readonly ChannelAvailability $availability,
    ) {}

    /**
     * @return int the number of outbound deliveries queued
     */
    public function dispatch(NotificationIntent $intent): int
    {
        $event = $this->catalog->event($intent->eventKey);
        $recipients = $this->resolver->resolve($event, $intent);

        $queued = [];

        foreach ($recipients as $user) {
            foreach ($this->deliver($user, $event, $intent) as $delivery) {
                $queued[] = $delivery;
            }
        }

        // Reached only after each notification transaction has committed.
        foreach ($queued as [$channel, $deliveryId]) {
            $this->dispatchJob($channel, $deliveryId);
        }

        return count($queued);
    }

    /**
     * @return list<array{0: NotificationChannel, 1: int}>
     */
    private function deliver(User $user, NotificationEvent $event, NotificationIntent $intent): array
    {
        return DB::transaction(function () use ($user, $event, $intent): array {
            if (! $this->claim($user, $intent, $event)) {
                return [];
            }

            $message = $event->message(
                $user->locale ?: (string) config('dukavo.locales.default', 'en'),
                $intent->data,
            );

            $notification = Notification::query()->create([
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'tenant_id' => $intent->tenantId,
                'scope' => $event->scope,
                'event_key' => $event->key,
                'category' => $event->category,
                'severity' => $intent->severity ?? $event->severity,
                'title' => $message['title'],
                'body' => $message['body'],
                'action_url' => $intent->actionUrl,
                'data' => $intent->data,
                'dedupe_key' => $intent->dedupeKey !== '' ? $intent->dedupeKey : null,
            ]);

            $queued = [];

            foreach ($this->channelsFor($user, $event) as $channel) {
                $delivery = $this->createDelivery($notification, $user, $event, $channel);

                if ($delivery->status === NotificationDeliveryStatus::Queued) {
                    $queued[] = [$channel, (int) $delivery->getKey()];
                }
            }

            return $queued;
        });
    }

    /**
     * Record the delivery attempt, or its skip, exactly as it stands.
     *
     * A channel that cannot be used — unconfigured, missing destination, or an
     * account that has not verified its email — is stored as `skipped` with the
     * reason and no job is dispatched. Only a channel that can actually send
     * gets a queued delivery and a job, so no worker ever processes a delivery
     * that was never going anywhere.
     */
    private function createDelivery(
        Notification $notification,
        User $user,
        NotificationEvent $event,
        NotificationChannel $channel,
    ): NotificationDelivery {
        $reason = $this->skipReason($user, $channel);

        return NotificationDelivery::query()->create([
            'notification_id' => $notification->getKey(),
            'event_key' => $event->key,
            'channel' => $channel,
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'recipient_ref' => $this->mask($user, $channel),
            'status' => $reason === null ? NotificationDeliveryStatus::Queued : NotificationDeliveryStatus::Skipped,
            'attempts' => 0,
            'error' => $reason,
            'queued_at' => $reason === null ? now() : null,
        ]);
    }

    private function skipReason(User $user, NotificationChannel $channel): ?string
    {
        if (! $this->availability->isAvailable($channel)) {
            return $this->availability->reason($channel);
        }

        if ($channel === NotificationChannel::Mail) {
            if (blank($user->email)) {
                return 'email_missing';
            }

            if (config('dukavo.notifications.require_verified_email', true) && $user->email_verified_at === null) {
                return 'email_unverified';
            }
        }

        if ($channel === NotificationChannel::WhatsApp && blank($user->phone)) {
            return 'phone_missing';
        }

        return null;
    }

    /**
     * @return list<NotificationChannel>
     */
    private function channelsFor(User $user, NotificationEvent $event): array
    {
        // A platform event has no user preference switch: platform alerts are
        // operational, and only channels the event declares are used.
        if ($event->preference === null) {
            return $event->channels;
        }

        $preferences = $user->notificationPreferences();

        return array_values(array_filter(
            $event->channels,
            fn (NotificationChannel $channel): bool => (bool) ($preferences[$channel->preferenceKey()][$event->preference] ?? false),
        ));
    }

    /**
     * Claim the right to notify this recipient for this event.
     *
     * A one-shot event (no cooldown) is refused forever once claimed. A
     * recurring event is refused until its cooldown has elapsed. The unique key
     * plus a row lock make the claim atomic even under concurrent dispatch, and
     * a lost insert race is treated as "already claimed".
     */
    private function claim(User $user, NotificationIntent $intent, NotificationEvent $event): bool
    {
        $key = $intent->eventKeyFor((int) $user->getKey());

        $existing = NotificationEventKey::query()->where('key', $key)->lockForUpdate()->first();

        if ($existing instanceof NotificationEventKey) {
            if ($event->cooldownSeconds === null) {
                return false;
            }

            if ($existing->last_notified_at !== null
                && $existing->last_notified_at->greaterThan(now()->subSeconds($event->cooldownSeconds))) {
                return false;
            }

            $existing->forceFill([
                'last_notified_at' => now(),
                'notifications_count' => $existing->notifications_count + 1,
            ])->save();

            return true;
        }

        try {
            NotificationEventKey::query()->create([
                'key' => $key,
                'scope' => $event->scope->value,
                'last_notified_at' => now(),
                'notifications_count' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request claimed it first; the notification already exists.
            return false;
        }

        return true;
    }

    private function dispatchJob(NotificationChannel $channel, int $deliveryId): void
    {
        match ($channel) {
            NotificationChannel::Mail => SendNotificationEmail::dispatch($deliveryId),
            NotificationChannel::WhatsApp => SendNotificationWhatsApp::dispatch($deliveryId),
        };
    }

    private function mask(User $user, NotificationChannel $channel): ?string
    {
        if ($channel === NotificationChannel::Mail) {
            return $this->maskEmail($user->email);
        }

        return $user->phone !== null ? $this->maskPhone($user->phone) : null;
    }

    private function maskEmail(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private function maskPhone(string $phone): string
    {
        return '•••'.mb_substr($phone, -4);
    }
}
