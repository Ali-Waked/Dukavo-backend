<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BundleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BundleItem $resource
 */
class BundleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'quantity' => $this->quantity,
            'position' => $this->position,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'status' => $this->product->status->value,
                'currency' => $this->product->currency,
            ] : null),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
                'price' => (float) $this->variant->price,
                'is_default' => (bool) $this->variant->is_default,
            ] : null),
        ];
    }
}
