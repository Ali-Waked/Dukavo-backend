<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Services\Notifications\Senders\WhatsAppNotificationSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one queued WhatsApp delivery.
 *
 * Mirrors {@see SendNotificationEmail}: id-only payload, terminal-state guard,
 * and a failure that is recorded before it is retried. The channel is currently
 * unconfigured, so in practice this job records a skip; it exists so the day the
 * transport is enabled no other wiring has to change.
 */
final class SendNotificationWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = (int) config('dukavo.notifications.retry.times', 3);
        $this->backoff = (array) config('dukavo.notifications.retry.backoff', [60, 300]);
        $this->onQueue(NotificationChannel::WhatsApp->queue());
    }

    public function handle(WhatsAppNotificationSender $sender): void
    {
        $delivery = NotificationDelivery::query()->with('notification')->find($this->deliveryId);

        if (! $delivery instanceof NotificationDelivery || $delivery->isTerminal()) {
            return;
        }

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Processing,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        try {
            $sender->send($delivery);
        } catch (Throwable $exception) {
            $delivery->markFailed($exception->getMessage());

            if ($this->attempts() < $this->tries) {
                throw $exception;
            }

            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery instanceof NotificationDelivery && ! $delivery->isTerminal()) {
            $delivery->markFailed($exception?->getMessage() ?? 'WhatsApp delivery failed.');
        }
    }
}
