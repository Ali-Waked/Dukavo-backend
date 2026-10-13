<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Customer $resource
 */
class CustomerResource extends JsonResource
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
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'locale' => $this->locale,
            'notes' => $this->notes,
            'orders_count' => $this->whenCounted('orders'),
            // Computed by the controller with a scoped SQL aggregate so it never
            // triggers a lazy load and never counts cancelled, refunded or
            // unpaid orders. `SUM` over no rows is null, which reads as zero.
            'total_spent' => $this->when(
                array_key_exists('total_spent', $this->resource->getAttributes()),
                fn (): float => round((float) ($this->total_spent ?? 0), 2),
            ),
            'last_order_at' => $this->last_order_at?->toIso8601String(),
            'addresses' => CustomerAddressResource::collection($this->whenLoaded('addresses')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
