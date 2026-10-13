<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProductVariant $resource
 */
class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price' => (float) $this->price,
            'cost' => (float) $this->cost,
            'is_default' => $this->is_default,
            'position' => $this->position,
            'stock' => $this->whenLoaded('inventories', fn () => [
                'quantity' => (int) $this->inventories->sum('quantity'),
                'reserved' => (int) $this->inventories->sum('reserved_quantity'),
                'available' => (int) $this->inventories->sum(fn ($inventory): int => $inventory->available()),
            ]),
        ];
    }
}
