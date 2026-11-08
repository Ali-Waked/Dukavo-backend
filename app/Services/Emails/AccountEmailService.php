<?php

declare(strict_types=1);

namespace App\Services\Emails;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendAccountEmail;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sends transactional account and security email through the delivery ledger.
 *
 * Account mail deliberately bypasses the notification preference switch: a
 * password reset, verification link, invitation or new-device warning is not
 * optional. It does not bypass the ledger, however. Every attempt is recorded as
 * a `notification_deliveries` row — with no notification id, because there is no
 * in-app copy — so a queued, sent or failed security email is still auditable.
 *
 * The row carries the template and its data so the queued job can render the
 * exact message after the request has ended, and an optional dedupe key gives
 * one-shot messages (a welcome, for example) the same duplicate protection as
 * notifications.
 */
final class AccountEmailService
{
    /**
     * @var list<string>
     */
    public const array TEMPLATES = [
        'welcome',
        'invitation',
        'verify',
        'reset',
        'password_changed',
        'new_device',
        'role_changed',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(
        User $user,
        string $template,
        array $data = [],
        ?string $actionUrl = null,
        ?string $dedupeKey = null,
    ): NotificationDelivery {
        if (! in_array($template, self::TEMPLATES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown account email template [%s].', $template));
        }

        $data += [
            'name' => $user->name,
            'email' => $user->email,
        ];

        if ($dedupeKey !== null) {
            $existing = NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing instanceof NotificationDelivery) {
                return $existing;
            }
        }

        try {
            $delivery = NotificationDelivery::query()->create([
                'notification_id' => null,
                'event_key' => 'account.'.$template,
                'dedupe_key' => $dedupeKey,
                'template' => $template,
                'payload' => ['data' => $data, 'action_url' => $actionUrl],
                'channel' => NotificationChannel::Mail,
                'recipient_type' => $user->getMorphClass(),
                'recipient_id' => $user->getKey(),
                'recipient_ref' => $this->maskEmail($user->email),
                'status' => blank($user->email) ? NotificationDeliveryStatus::Skipped : NotificationDeliveryStatus::Queued,
                'attempts' => 0,
                'error' => blank($user->email) ? 'email_missing' : null,
                'queued_at' => blank($user->email) ? null : now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        if ($delivery->status === NotificationDeliveryStatus::Queued) {
            // The caller may still be inside the transaction that granted the
            // role or created the invitation. Sending is deferred until it
            // commits, so an account email can never describe a rolled-back
            // change.
            DB::afterCommit(fn () => SendAccountEmail::dispatch((int) $delivery->getKey()));
        }

        return $delivery;
    }

    private function maskEmail(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
