<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationSeverity;
use App\Events\Notifications\NotificationCreated;
use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationPush;
use App\Jobs\SendNotificationWhatsApp;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEventKey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $broadcasts = [];

        foreach ($recipients as $user) {
            $result = $this->deliver($user, $event, $intent);

            foreach ($result['deliveries'] as $delivery) {
                $queued[] = $delivery;
            }

            if ($result['notification'] instanceof Notification && $result['in_app']) {
                $broadcasts[] = $result['notification'];
            }
        }

        // The delivery rows are committed, but the caller may still be inside a
        // wider business transaction. Deferring the jobs and the broadcast until
        // *that* commits is what stops a rolled-back order from ever reaching a
        // browser or an inbox.
        DB::afterCommit(function () use ($queued, $broadcasts): void {
            foreach ($broadcasts as $notification) {
                event(new NotificationCreated($notification));
            }

            foreach ($queued as [$channel, $deliveryId]) {
                $this->dispatchJob($channel, $deliveryId);
            }
        });

        return count($queued);
    }

    /**
     * @return array{deliveries: list<array{0: NotificationChannel, 1: int}>, notification: Notification|null, in_app: bool}
     */
    private function deliver(User $user, NotificationEvent $event, NotificationIntent $intent): array
    {
        return DB::transaction(function () use ($user, $event, $intent): array {
            $channels = $this->channelsFor($user, $event, $intent);
            $inApp = $this->shouldCreateInApp($user, $event, $intent);

            // Nothing is owed to this person: no in-app copy and no transport.
            // Refusing here (before claiming) means a later change to their
            // preferences can still deliver the event instead of finding it
            // permanently consumed.
            if (! $inApp && $channels === []) {
                return ['deliveries' => [], 'notification' => null, 'in_app' => false];
            }

            if (! $this->claim($user, $intent, $event)) {
                return ['deliveries' => [], 'notification' => null, 'in_app' => false];
            }

            $message = $event->message(
                $user->locale ?: (string) config('dukavo.locales.default', 'en'),
                $intent->data,
            );

            $notification = Notification::query()->create([
                // Assigned explicitly rather than only in the model's creating
                // hook: callers such as the demo seeders run with model events
                // muted, and a notification still needs its identifier there.
                'id' => (string) Str::uuid(),
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'tenant_id' => $intent->tenantId,
                'store_id' => $intent->storeId,
                'scope' => $event->scope,
                'event_key' => $event->key,
                'category' => $event->category,
                'severity' => $intent->severity ?? $event->severity,
                'priority' => ($intent->priority ?? $this->priorityFor($event))->value,
                'title' => $message['title'],
                'body' => $message['body'],
                'action_url' => $intent->actionUrl,
                'data' => $intent->data,
                'related_type' => $intent->related?->getMorphClass(),
                'related_id' => $intent->related?->getKey(),
                'dedupe_key' => $intent->dedupeKey !== '' ? $intent->dedupeKey : null,
                // An in-app opt-out hides the row from the bell but keeps the
                // authoritative record for audit and for any outbound channel.
                'archived_at' => $inApp ? null : now(),
            ]);

            $queued = [];

            foreach ($channels as $channel) {
                $delivery = $this->createDelivery($notification, $user, $event, $channel);

                if ($delivery->status === NotificationDeliveryStatus::Queued) {
                    $queued[] = [$channel, (int) $delivery->getKey()];
                }
            }

            return ['deliveries' => $queued, 'notification' => $notification, 'in_app' => $inApp];
        });
    }

    private function priorityFor(NotificationEvent $event): NotificationPriority
    {
        return match ($event->severity) {
            NotificationSeverity::Critical => NotificationPriority::Critical,
            NotificationSeverity::Warning => NotificationPriority::High,
            default => NotificationPriority::Normal,
        };
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

        if ($channel === NotificationChannel::Push && ! $user->notificationDevices()->active()->exists()) {
            return 'no_active_device';
        }

        if ($channel === NotificationChannel::WhatsApp && blank($user->phone)) {
            return 'phone_missing';
        }

        return null;
    }

    /**
     * Should this recipient see an entry in the bell and the centre?
     *
     * Platform operational alerts have no preference switch. Security notices
     * are mandatory. Everything else obeys the user's in-app setting, enforced
     * here rather than by hiding the row in the client.
     */
    private function shouldCreateInApp(User $user, NotificationEvent $event, NotificationIntent $intent): bool
    {
        if ($event->preference === null) {
            return true;
        }

        if (in_array($event->preference, User::MANDATORY_NOTIFICATION_EVENTS, true)) {
            return true;
        }

        $preferences = $user->notificationPreferences($intent->tenantId);

        return (bool) ($preferences['in_app'][$event->preference] ?? true);
    }

    /**
     * @return list<NotificationChannel>
     */
    private function channelsFor(User $user, NotificationEvent $event, NotificationIntent $intent): array
    {
        // Push is offered by every event, but a deployment without Firebase
        // has no transport to record a skip against. Writing one skipped row
        // per event per user would fill the delivery table with noise that no
        // operator can act on, so an unavailable push is omitted entirely.
        $pushUsable = $this->availability->isAvailable(NotificationChannel::Push);

        // A platform event has no user preference switch: platform alerts are
        // operational, and only channels the event declares are used.
        if ($event->preference === null) {
            return array_values(array_filter(
                $event->channels,
                fn (NotificationChannel $channel): bool => $channel !== NotificationChannel::Push || $pushUsable,
            ));
        }

        $preferences = $user->notificationPreferences($intent->tenantId);

        return array_values(array_filter(
            $event->channels,
            fn (NotificationChannel $channel): bool => ($channel !== NotificationChannel::Push || $pushUsable)
                && (bool) ($preferences[$channel->preferenceKey()][$event->preference] ?? false),
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
            NotificationChannel::Push => SendNotificationPush::dispatch($deliveryId),
            NotificationChannel::WhatsApp => SendNotificationWhatsApp::dispatch($deliveryId),
        };
    }

    private function mask(User $user, NotificationChannel $channel): ?string
    {
        if ($channel === NotificationChannel::Mail) {
            return $this->maskEmail($user->email);
        }

        if ($channel === NotificationChannel::Push) {
            return null;
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
