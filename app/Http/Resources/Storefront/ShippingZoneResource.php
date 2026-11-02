<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ShippingZone $resource
 */
class ShippingZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'areas' => $this->areas ?? [],
            'fee' => (float) $this->fee,
            'free_shipping_threshold' => $this->free_shipping_threshold === null
                ? null
                : (float) $this->free_shipping_threshold,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
