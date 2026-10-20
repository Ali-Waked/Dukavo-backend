<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single outbound delivery attempt for one channel.
 *
 * The record is the audit trail behind a notification: it says what was tried,
 * whether the provider accepted it, and — only when there is evidence — that it
 * was delivered. `recipient_ref` holds a masked destination so an operations
 * view can show activity without revealing an address.
 */
#[Fillable([
    'notification_id', 'event_key', 'channel', 'recipient_type', 'recipient_id',
    'recipient_ref', 'status', 'attempts', 'provider_message_id', 'error',
    'queued_at', 'sent_at', 'delivered_at', 'failed_at',
])]
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationDeliveryStatus::class,
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Record provider acceptance. Only ever called after the provider returned
     * a message id, never merely because a job was queued.
     */
    public function markSent(?string $providerMessageId = null): void
    {
        $this->forceFill([
            'status' => NotificationDeliveryStatus::Sent,
            'provider_message_id' => $providerMessageId ?? $this->provider_message_id,
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => NotificationDeliveryStatus::Delivered,
            'delivered_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'error' => $error,
            'failed_at' => now(),
        ])->save();
    }

    /**
     * Record that a channel was deliberately not used (unconfigured provider,
     * missing destination, disabled preference). This is not a failure and
     * leaves the notification intact.
     */
    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => NotificationDeliveryStatus::Skipped,
            'error' => $reason,
        ])->save();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', NotificationDeliveryStatus::Failed);
    }
}
