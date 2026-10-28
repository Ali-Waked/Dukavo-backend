<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\VoiceMessageStatus;
use App\Models\VoiceMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read VoiceMessage $resource
 */
class VoiceMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mediaAvailable = $this->mediaAvailable();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'message_id' => $this->conversation_message_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'transcript' => $this->transcript,
            'language' => $this->language,
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'extraction' => $this->extraction,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'attempt_count' => (int) $this->attempt_count,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'applied_cart_id' => $this->applied_cart_id,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'media' => [
                'available' => $mediaAvailable,
                'url' => $mediaAvailable
                    ? sprintf(
                        '/tenant/conversations/%d/messages/%d/media',
                        $this->conversation_id,
                        $this->conversation_message_id,
                    )
                    : null,
                'mime_type' => $this->media_mime_type,
                'size' => $this->media_size !== null ? (int) $this->media_size : null,
                'purged_at' => $this->media_purged_at?->toIso8601String(),
            ],
            'capabilities' => [
                'retry' => $mediaAvailable && $this->status === VoiceMessageStatus::Failed,
                'apply' => $this->status === VoiceMessageStatus::Ready && is_array($this->extraction),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
