<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StockTransfer $resource
 */
class StockTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_store_id' => $this->from_store_id,
            'to_store_id' => $this->to_store_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'quantity' => $this->quantity,
            'note' => $this->note,
            'from_movement_id' => $this->from_movement_id,
            'to_movement_id' => $this->to_movement_id,
            'from_store' => $this->whenLoaded('fromStore', fn () => [
                'id' => $this->fromStore->id,
                'name' => $this->fromStore->name,
            ]),
            'to_store' => $this->whenLoaded('toStore', fn () => [
                'id' => $this->toStore->id,
                'name' => $this->toStore->name,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
            ]),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
