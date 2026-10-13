<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read OrderItem $resource
 */
class OrderItemResource extends JsonResource
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
            'name' => $this->name,
            'sku' => $this->sku,
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'line_total' => (float) $this->line_total,
        ];
    }
}
