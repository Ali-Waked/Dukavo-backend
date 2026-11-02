<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer-safe order view.
 *
 * Internal ids, cost snapshots, merchant notes and other customers' data never
 * appear here. The tracking token is included only on the create response: it
 * is the secret that authorises every later read.
 *
 * @property-read Order $resource
 */
class PublicOrderResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includeTrackingToken = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'payment_method' => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'subtotal' => (float) $this->subtotal,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'shipping_total' => (float) $this->shipping_total,
            'grand_total' => (float) $this->grand_total,
            'currency' => $this->currency,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'shipping_address' => $this->shipping_address,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values()),
            'store' => $this->whenLoaded('store', fn () => [
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'email' => $this->store->email,
                'phone' => $this->store->phone,
            ]),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'tracking_token' => $this->when($this->includeTrackingToken, fn () => $this->tracking_token),
        ];
    }
}
