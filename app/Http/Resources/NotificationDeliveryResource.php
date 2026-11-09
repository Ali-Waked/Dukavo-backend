<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery row as the platform operations screen sees it.
 *
 * The payload and message body are deliberately absent: an operator needs to
 * know whether a channel accepted a message, not read a merchant's private
 * notification. The recipient is shown by display name and masked destination.
 *
 * @mixin NotificationDelivery
 */
class NotificationDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'notification_id' => $this->notification_id,
            'event_key' => $this->event_key,
            'channel' => $this->channel->value,
            'provider' => $this->provider(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'attempts' => $this->attempts,
            'recipient' => [
                'id' => $this->recipient_id,
                'name' => $this->recipientName(),
                'ref' => $this->recipient_ref,
            ],
            'tenant' => $this->tenant(),
            'error' => $this->error,
            'failure_type' => $this->failure_type,
            'provider_message_id' => $this->provider_message_id,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'retryable' => $this->status === NotificationDeliveryStatus::Failed
                && ! $this->isPermanentFailure()
                && in_array($this->channel, [NotificationChannel::Mail, NotificationChannel::Push], true),
        ];
    }

    private function provider(): string
    {
        return match ($this->channel) {
            NotificationChannel::Mail => (string) config('mail.default', 'smtp'),
            NotificationChannel::Push => 'firebase',
            NotificationChannel::WhatsApp => 'whatsapp',
        };
    }

    private function recipientName(): ?string
    {
        if (! $this->relationLoaded('recipient')) {
            return null;
        }

        $recipient = $this->recipient;

        return $recipient instanceof Model
            ? (string) $recipient->getAttribute('name')
            : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function tenant(): ?array
    {
        if (! $this->relationLoaded('notification') || $this->notification === null) {
            return null;
        }

        $tenant = $this->notification->relationLoaded('tenant')
            ? $this->notification->tenant
            : null;

        if ($tenant === null) {
            return null;
        }

        return ['id' => (int) $tenant->getKey(), 'name' => (string) $tenant->name];
    }
}
