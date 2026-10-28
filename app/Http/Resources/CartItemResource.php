<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CartItem $resource
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inventory = $this->variant?->inventories
            ?->firstWhere('store_id', $this->cart?->store_id);

        $available = $inventory?->available() ?? 0;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
            'discount_total' => (float) $this->discount_total,
            'line_total' => (float) $this->line_total,
            'available' => $available,
            'is_available' => $available >= $this->quantity,
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'is_default' => $this->variant->is_default,
            ]),
        ];
    }
}
