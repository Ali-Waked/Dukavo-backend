<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform campaign as the administration screen sees it.
 *
 * Counters are exposed both raw and as a percentage-friendly total so the
 * screen never has to guess whether `sent_count` includes skipped recipients.
 * Lifecycle flags (`can_edit`, `can_publish`, `can_cancel`) come from the same
 * enum the service enforces, keeping the buttons and the backend in agreement.
 *
 * @mixin NotificationCampaign
 */
class NotificationCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category,
            'severity' => $this->severity?->value ?? 'info',
            'priority' => $this->priority?->value ?? 'normal',
            'action_url' => $this->action_url,
            'channels' => $this->channels ?? [],
            'audience' => $this->audience ?? ['type' => 'all'],
            'is_marketing' => (bool) $this->is_marketing,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'counts' => [
                'total' => $this->total_recipients,
                'queued' => $this->queued_count,
                'sent' => $this->sent_count,
                'failed' => $this->failed_count,
                'skipped' => $this->skipped_count,
            ],
            'creator' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'can_edit' => $this->isEditable(),
            'can_publish' => in_array($this->status?->value, ['draft', 'scheduled', 'failed'], true),
            'can_cancel' => ! $this->isTerminal() && $this->status?->value !== 'published',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
