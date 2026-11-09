<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\AuditAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Jobs\SendAccountEmail;
use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationPush;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Operator-initiated retry of one failed delivery.
 *
 * A retry is allowed only from a recorded failure that is not permanent, and
 * only on a channel this installation can actually send. The row is moved back
 * to `queued` and a single job is dispatched after the transaction commits, so
 * a double-click cannot enqueue two sends: the second request sees a row that
 * is no longer failed.
 */
final class NotificationDeliveryRetryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function retry(NotificationDelivery $delivery, User $actor): NotificationDelivery
    {
        if ($delivery->status !== NotificationDeliveryStatus::Failed) {
            throw BusinessRuleViolation::because(
                'Only a failed delivery can be retried.',
                errorCode: 'delivery_not_retryable',
            );
        }

        if ($delivery->isPermanentFailure()) {
            throw BusinessRuleViolation::because(
                'This failure is permanent; retrying would fail identically.',
                errorCode: 'delivery_permanent_failure',
            );
        }

        $channel = $delivery->channel;

        if (! in_array($channel, [NotificationChannel::Mail, NotificationChannel::Push], true)) {
            throw BusinessRuleViolation::because(
                'This channel has no retry transport.',
                errorCode: 'delivery_channel_not_retryable',
            );
        }

        if ($channel === NotificationChannel::Mail
            && $delivery->notification_id === null
            && blank($delivery->template)) {
            throw BusinessRuleViolation::because(
                'This delivery has no template to render.',
                errorCode: 'delivery_template_missing',
            );
        }

        DB::transaction(function () use ($delivery, $actor): void {
            $delivery->forceFill([
                'status' => NotificationDeliveryStatus::Queued,
                'error' => null,
                'failure_type' => null,
                'queued_at' => now(),
                'failed_at' => null,
            ])->save();

            $this->audit->record(AuditAction::NotificationDeliveryRetried, $delivery, [
                'new' => [
                    'channel' => $delivery->channel->value,
                    'notification_id' => $delivery->notification_id,
                    'attempts' => $delivery->attempts,
                ],
            ], $actor);
        });

        DB::afterCommit(function () use ($delivery, $channel): void {
            match ($channel) {
                NotificationChannel::Mail => $delivery->notification_id !== null
                    ? SendNotificationEmail::dispatch((int) $delivery->getKey())
                    : SendAccountEmail::dispatch((int) $delivery->getKey()),
                NotificationChannel::Push => SendNotificationPush::dispatch((int) $delivery->getKey()),
                default => null,
            };
        });

        return $delivery->refresh();
    }
}
