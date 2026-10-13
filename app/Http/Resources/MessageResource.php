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
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'type' => $this->type->value,
            'body' => $this->body,
            'meta' => $this->meta,
            // Only outbound messages have a delivery lifecycle; inbound receipts
            // are the provider telling us something already happened.
            'delivery_status' => $this->delivery_status,
            'delivery_status_label' => $this->delivery_status_label(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'delivery_error' => $this->meta['error'] ?? null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
