<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CartStatus;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Cart $resource
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'version' => $this->version,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'shipping_total' => (float) $this->shipping_total,
            'grand_total' => (float) $this->grand_total,
            'tax_rate' => (float) $this->tax_rate,
            'shipping_address' => $this->shipping_address,
            'notes' => $this->notes,
            'confirmation_requested_at' => $this->confirmation_requested_at?->toIso8601String(),
            'confirmation_sent_at' => $this->confirmation_sent_at?->toIso8601String(),
            'confirmation_message_id' => $this->confirmation_message_id,
            'confirmation_error' => $this->confirmation_error,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'currency' => $this->store->currency,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ] : null),
            'conversation' => $this->whenLoaded('conversation', fn () => $this->conversation ? [
                'id' => $this->conversation->id,
                'channel' => $this->conversation->channel,
                'display_name' => $this->conversation->display_name,
                'phone' => $this->conversation->phone,
                'reply_window' => $this->conversation->replyWindow(),
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status->value,
                'status_label' => $this->order->status->label(),
                'grand_total' => (float) $this->order->grand_total,
                'placed_at' => $this->order->placed_at?->toIso8601String(),
            ] : null),
            'capabilities' => [
                'edit' => $this->status->isEditable(),
                'request_confirmation' => in_array($this->status, [CartStatus::Draft, CartStatus::PendingConfirmation], true),
                'confirm' => $this->status === CartStatus::PendingConfirmation && $this->items->isNotEmpty(),
                'reopen' => $this->status === CartStatus::PendingConfirmation,
                'cancel' => ! $this->status->isFinal(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
