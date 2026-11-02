<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProductVariant $resource
 */
class PublicProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $available = (int) $this->inventories->sum(fn ($inventory): int => $inventory->available());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'price' => (float) $this->price,
            'is_default' => $this->is_default,
            'available' => max(0, $available),
            'in_stock' => $available > 0,
        ];
    }
}
