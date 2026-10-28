<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerMemory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CustomerMemory $resource
 */
class CustomerMemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'key' => $this->key,
            'value' => $this->value,
            'category' => $this->category,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'meta' => $this->meta,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'review_at' => $this->review_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
