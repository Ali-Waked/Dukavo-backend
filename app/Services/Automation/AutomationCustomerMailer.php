<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendAutomationCustomerEmail;
use App\Models\Customer;
use App\Models\NotificationDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Queues a transactional email to a customer on behalf of an automation.
 *
 * It uses the same delivery ledger as notification email, but targets a
 * customer rather than a teammate. The destination and locale are snapshotted
 * into the payload so the queued job never needs a cross-tenant customer query.
 */
final class AutomationCustomerMailer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function queue(
        int $tenantId,
        ?int $customerId,
        string $email,
        ?string $locale,
        string $subject,
        string $body,
        string $dedupeKey,
        ?string $actionUrl = null,
    ): NotificationDelivery {
        $email = trim($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The customer email address is not valid.');
        }

        if ($dedupeKey !== '') {
            $existing = NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing instanceof NotificationDelivery) {
                return $existing;
            }
        }

        try {
            $delivery = NotificationDelivery::query()->create([
                'notification_id' => null,
                'event_key' => 'automation.customer_email',
                'dedupe_key' => $dedupeKey,
                'template' => 'automation_customer',
                'payload' => [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'email' => $email,
                    'locale' => $locale ?: (string) config('dukavo.locales.default', 'en'),
                    'subject' => $subject,
                    'body' => $body,
                    'action_url' => $actionUrl,
                ],
                'channel' => NotificationChannel::Mail,
                'recipient_type' => Customer::class,
                'recipient_id' => $customerId,
                'recipient_ref' => $this->maskEmail($email),
                'status' => NotificationDeliveryStatus::Queued,
                'attempts' => 0,
                'queued_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        DB::afterCommit(fn () => SendAutomationCustomerEmail::dispatch((int) $delivery->getKey()));

        return $delivery;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
