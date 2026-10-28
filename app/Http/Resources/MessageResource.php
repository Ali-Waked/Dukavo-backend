<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ConversationMessage $resource
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = data_get($this->meta, 'media');
        $hasMedia = is_array($media) && filled($media['path'] ?? null);

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'type' => $this->type->value,
            'body' => $this->body,
            'external_id' => $this->external_id,
            'is_internal' => $this->isInternal(),
            'media' => $hasMedia ? [
                'url' => sprintf(
                    '/tenant/conversations/%d/messages/%d/media',
                    $this->conversation_id,
                    $this->id,
                ),
                'filename' => $media['filename'] ?? null,
                'mime_type' => $media['mime_type'] ?? null,
                'size' => $media['size'] ?? null,
            ] : null,
            'template' => data_get($this->meta, 'template'),
            'location' => data_get($this->meta, 'location'),
            'voice' => $this->whenLoaded(
                'voiceMessage',
                fn () => $this->voiceMessage !== null
                    ? new VoiceMessageResource($this->voiceMessage)
                    : null,
            ),
            // Only outbound messages have a delivery lifecycle; inbound receipts
            // are the provider telling us something already happened.
            'delivery_status' => $this->delivery_status,
            'delivery_status_label' => $this->delivery_status_label(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,
            'delivery_error' => $this->failure_reason ?? data_get($this->meta, 'error'),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'retry_of_id' => $this->retry_of_id,
            'attempt_count' => (int) $this->attempt_count,
            'can_retry' => $this->canRetry(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
