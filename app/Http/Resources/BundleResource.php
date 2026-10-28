<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Bundle $resource
 */
class BundleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'discount_type' => $this->discount_type->value,
            'discount_type_label' => $this->discount_type->label(),
            'discount_value' => (float) $this->discount_value,
            'currency' => $this->currency,
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'currency' => $this->store->currency,
            ] : null),
            'items' => BundleItemResource::collection($this->whenLoaded('items')),
            'item_count' => $this->whenLoaded('items', fn (): int => $this->items->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
