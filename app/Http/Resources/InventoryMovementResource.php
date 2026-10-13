<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read InventoryMovement $resource
 */
class InventoryMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_id' => $this->inventory_id,
            'user_id' => $this->user_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'quantity' => $this->quantity,
            // Signed so a client can render in/out without inspecting the type;
            // an adjustment is reported as the raw change it made.
            'direction' => $this->type->direction() > 0
                ? 'in'
                : ($this->type === InventoryMovementType::Adjustment ? ($this->quantity >= 0 ? 'in' : 'out') : 'out'),
            'balance_after' => $this->balance_after,
            'note' => $this->note,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'product' => $this->whenLoaded('inventory.product', fn () => $this->inventory?->product ? [
                'id' => $this->inventory->product->id,
                'name' => $this->inventory->product->name,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
