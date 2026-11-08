<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Services\Emails\EmailFailureClassifier;
use App\Services\Notifications\Senders\EmailNotificationSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one queued email delivery.
 *
 * The job carries only a delivery id, so it is tiny to serialise and always
 * reads the current state of the row. It refuses to touch a delivery that is
 * already terminal, which makes a duplicated queue entry harmless.
 *
 * A temporary transport failure marks the delivery `failed`, records why, and
 * rethrows so the queue can retry with backoff. A permanent failure — a rejected
 * recipient, a blocked message — is recorded once and *not* retried, because the
 * second attempt would fail identically. Once retries are exhausted the delivery
 * stays failed and the exception is reported rather than silently swallowed.
 */
final class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = (int) config('dukavo.notifications.retry.times', 3);
        $this->backoff = (array) config('dukavo.notifications.retry.backoff', [60, 300]);
        $this->onQueue(NotificationChannel::Mail->queue());
    }

    public function handle(EmailNotificationSender $sender, EmailFailureClassifier $classifier): void
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
            $type = $classifier->classify($exception);

            $delivery->markFailed($classifier->reason($exception), $type);

            if ($type === EmailFailureClassifier::PERMANENT || $this->attempts() >= $this->tries) {
                report($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery instanceof NotificationDelivery && ! $delivery->isTerminal()) {
            $classifier = app(EmailFailureClassifier::class);

            $delivery->markFailed(
                $exception !== null ? $classifier->reason($exception) : 'Email delivery failed.',
                $exception !== null ? $classifier->classify($exception) : null,
            );
        }
    }
}
