<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Mail\AccountEmail;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Emails\EmailFailureClassifier;
use App\Services\Emails\EmailTemplateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one queued account or security email.
 *
 * The delivery row carries the template and its data, so the message is
 * rendered from the ledger rather than from a request object that may no longer
 * exist. The recipient is re-read and re-checked because an address can change
 * between queueing and sending.
 */
final class SendAccountEmail implements ShouldQueue
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

    public function handle(EmailFailureClassifier $classifier, ?EmailTemplateService $templates = null): void
    {
        $templates ??= app(EmailTemplateService::class);

        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if (! $delivery instanceof NotificationDelivery || $delivery->isTerminal() || $delivery->status === NotificationDeliveryStatus::Skipped) {
            return;
        }

        $recipient = User::query()->find($delivery->recipient_id);

        if (! $recipient instanceof User || blank($recipient->email)) {
            $delivery->markSkipped('email_missing');

            return;
        }

        if (is_string($delivery->template) && ! $templates->isEnabled('account.'.$delivery->template)) {
            $delivery->markSkipped('template_disabled');

            return;
        }

        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $actionUrl = isset($payload['action_url']) && is_string($payload['action_url']) ? $payload['action_url'] : null;

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Processing,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        try {
            $sent = Mail::to($recipient->email)->send(new AccountEmail(
                recipient: $recipient,
                template: (string) $delivery->template,
                data: $data,
                actionUrl: $actionUrl,
            ));

            $delivery->markSent($sent?->getMessageId());
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
                $exception !== null ? $classifier->reason($exception) : 'Account email delivery failed.',
                $exception !== null ? $classifier->classify($exception) : null,
            );
        }
    }
}
