<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RecoveryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RecoveryEvent $resource
 */
class RecoveryEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'note' => $this->note,
            'meta' => $this->meta,
            'conversation_message_id' => $this->conversation_message_id,
            'user' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
