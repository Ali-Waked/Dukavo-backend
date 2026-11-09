<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationSeverity;
use App\Mail\AutomationCustomerMail;
use App\Models\NotificationDelivery;
use App\Services\Emails\EmailContentBuilder;
use App\Services\Emails\EmailFailureClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one queued automation email to a customer.
 *
 * It reads the destination and locale from the delivery payload, so it never
 * performs a cross-tenant customer lookup. A failed transport is classified the
 * same way as notification email, which keeps the retry decision consistent.
 */
final class SendAutomationCustomerEmail implements ShouldQueue
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

    public function handle(EmailContentBuilder $builder, EmailFailureClassifier $classifier): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if (! $delivery instanceof NotificationDelivery || $delivery->isTerminal()) {
            return;
        }

        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $email = trim((string) ($payload['email'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $locale = (string) ($payload['locale'] ?? 'en');

        if ($email === '' || $subject === '' || $body === '') {
            $delivery->markFailed('automation_email_payload_incomplete', 'permanent');

            return;
        }

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Processing,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        try {
            $content = $builder->buildEvent(
                eventKey: 'automation.customer_email',
                locale: $locale,
                data: [
                    'title' => $subject,
                    'body' => $body,
                    'order_number' => $payload['order_number'] ?? null,
                ],
                actionUrl: isset($payload['action_url']) ? (string) $payload['action_url'] : null,
                severity: NotificationSeverity::Info,
                tenantId: isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null,
                title: $subject,
            );

            Mail::to($email)
                ->locale($locale)
                ->send(new AutomationCustomerMail($subject, $content, $locale));

            $delivery->markSent();
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
                $exception !== null ? $classifier->reason($exception) : 'Automation email failed.',
                $exception !== null ? $classifier->classify($exception) : null,
            );
        }
    }
}
