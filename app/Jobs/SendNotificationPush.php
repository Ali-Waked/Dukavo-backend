<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationPriority;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\Push\Contracts\PushTransport;
use App\Services\Notifications\Push\PushDeliveryException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one notification to every active installation a user has.
 *
 * The job carries only the delivery id. On each attempt it reads the current
 * device list, so a phone registered after the notification was queued still
 * receives it, and a phone that logged out is not resurrected. Tokens Firebase
 * rejects are revoked immediately; a transient failure rethrows so the queue
 * can retry with backoff. Already-sent devices are remembered in the delivery
 * payload, so a retry never double-alerts a phone that succeeded on the first
 * attempt.
 */
final class SendNotificationPush implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = (int) config('dukavo.notifications.retry.times', 3);
        $this->backoff = (array) config('dukavo.notifications.retry.backoff', [60, 300]);
        $this->onQueue(NotificationChannel::Push->queue());
    }

    public function handle(PushTransport $transport): void
    {
        $delivery = NotificationDelivery::query()->with('notification')->find($this->deliveryId);

        if (! $delivery instanceof NotificationDelivery || $delivery->isTerminal()) {
            return;
        }

        $notification = $delivery->notification;
        $user = User::query()->find($delivery->recipient_id);

        if ($notification === null || ! $user instanceof User) {
            $delivery->markFailed('notification_or_recipient_missing', 'permanent');

            return;
        }

        $devices = $user->notificationDevices()->active()->get();

        if ($devices->isEmpty()) {
            $delivery->markSkipped('no_active_device');

            return;
        }

        if (! $transport->isConfigured()) {
            $delivery->markSkipped('push_not_configured');

            return;
        }

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Processing,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        $state = $delivery->provider_payload ?? [];
        $sentDevices = array_map('intval', $state['sent_device_ids'] ?? []);
        $messageIds = $state['message_ids'] ?? [];
        $permanent = [];
        $transient = [];

        foreach ($devices as $device) {
            if (in_array((int) $device->getKey(), $sentDevices, true)) {
                continue;
            }

            try {
                $messageId = $transport->send(
                    device: $device,
                    title: (string) $notification->title,
                    body: (string) ($notification->body ?? ''),
                    data: $this->data($notification),
                    category: (string) $notification->category,
                    priority: $notification->priority ?? NotificationPriority::Normal,
                );

                $sentDevices[] = (int) $device->getKey();
                $messageIds[(int) $device->getKey()] = $messageId;
            } catch (PushDeliveryException $exception) {
                if ($exception->permanent) {
                    $device->revoke();
                    $permanent[] = $exception->getMessage();
                } else {
                    $transient[] = $exception->getMessage();
                }
            } catch (Throwable $exception) {
                $transient[] = $exception->getMessage();
            }
        }

        $delivery->forceFill([
            'provider_payload' => [
                ...$state,
                'sent_device_ids' => array_values(array_unique($sentDevices)),
                'message_ids' => $messageIds,
            ],
        ])->save();

        if ($sentDevices !== []) {
            // At least one device accepted the message.
            if ($transient === []) {
                $delivery->markSent((string) reset($messageIds));

                return;
            }

            // Some devices succeeded and some are still retryable. Persist the
            // successes above, then let the queue retry only the rest.
            if ($this->attempts() >= $this->tries) {
                $delivery->markFailed($this->reason($transient), 'temporary');

                return;
            }

            // The row is recorded as a retryable failure before the queue is
            // asked to try again, so an operator inspecting it now sees the
            // truth instead of a delivery stuck in `processing`.
            $delivery->markFailed($this->reason($transient), 'temporary');

            throw new \RuntimeException('Push partially failed: '.$this->reason($transient));
        }

        if ($transient !== []) {
            $delivery->markFailed($this->reason($transient), 'temporary');

            if ($this->attempts() >= $this->tries) {
                return;
            }

            throw new \RuntimeException('Push delivery failed: '.$this->reason($transient));
        }

        // Every token was permanently rejected. There is nothing to retry; the
        // devices have been revoked so the next notification is not attempted.
        $delivery->markFailed($this->reason($permanent ?: ['no_deliverable_device']), 'permanent');
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery instanceof NotificationDelivery && ! $delivery->isTerminal()) {
            $delivery->markFailed(
                $exception?->getMessage() ?: 'Push delivery failed.',
                'temporary',
            );
        }
    }

    /**
     * The small, non-sensitive payload the app needs to navigate.
     *
     * The full notification body lives in Laravel and is fetched over an
     * authenticated request; a push payload may pass through Google and the
     * device notification shade, so it never carries customer data or tokens.
     *
     * @return array<string, string>
     */
    private function data(Notification $notification): array
    {
        $data = [
            'notification_id' => (string) $notification->getKey(),
            'event_key' => (string) $notification->event_key,
            'category' => (string) $notification->category,
            'priority' => (string) ($notification->priority?->value ?? 'normal'),
            'severity' => (string) ($notification->severity?->value ?? 'info'),
            'type' => 'notification',
        ];

        if (filled($notification->action_url)) {
            $data['action_url'] = (string) $notification->action_url;
        }

        if ($notification->tenant_id !== null) {
            $data['tenant_id'] = (string) $notification->tenant_id;
        }

        if ($notification->store_id !== null) {
            $data['store_id'] = (string) $notification->store_id;
        }

        return $data;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function reason(array $reasons): string
    {
        return mb_substr(implode('; ', array_unique($reasons)), 0, 1000);
    }
}
