<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\RecoveryOpportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recovery opportunity, with the real records an agent needs to act.
 *
 * `recovered_value` is read from the linked order and is null for a cancelled
 * order. The opportunity never carries a revenue figure of its own making.
 *
 * @property-read RecoveryOpportunity $resource
 */
class RecoveryOpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversation = $this->whenLoaded('conversation');
        $customer = $this->whenLoaded('customer');
        $cart = $this->whenLoaded('cart');
        $order = $this->whenLoaded('order');
        $replyWindow = $this->conversation?->replyWindow();

        $orderIsBillable = $this->order !== null && $this->order->status !== OrderStatus::Cancelled;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'conversation_id' => $this->conversation_id,
            'customer_id' => $this->customer_id,
            'cart_id' => $this->cart_id,
            'order_id' => $this->order_id,
            'assigned_to' => $this->assigned_to,
            'estimated_value' => $this->estimated_value !== null ? (float) $this->estimated_value : null,
            'currency' => $this->currency,
            'recovered_value' => $orderIsBillable ? (float) $this->order->grand_total : null,
            'recovered_currency' => $orderIsBillable ? $this->order->currency : null,
            'contact_attempts' => (int) $this->contact_attempts,
            'detected_at' => $this->detected_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'recovered_at' => $this->recovered_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'dismissal_reason' => $this->dismissal_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'can_contact' => $this->status->isOpen()
                && $this->customer?->whatsapp_opt_out_at === null
                && $this->conversation !== null
                && ! $this->conversation->status->isClosed(),
            'can_text' => (bool) ($replyWindow['is_open'] ?? false),
            'requires_template' => (bool) ($replyWindow['requires_template'] ?? true),
            'conversation' => $conversation !== null && $this->conversation !== null ? [
                'id' => $this->conversation->id,
                'channel' => $this->conversation->channel,
                'display_name' => $this->conversation->display_name,
                'phone' => $this->conversation->phone,
                'status' => $this->conversation->status->value,
                'last_inbound_at' => $this->conversation->last_inbound_at?->toIso8601String(),
                'reply_window' => $replyWindow,
            ] : null,
            'customer' => $customer !== null && $this->customer !== null ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'locale' => $this->customer->locale,
                'whatsapp_opt_out_at' => $this->customer->whatsapp_opt_out_at?->toIso8601String(),
            ] : null,
            'cart' => $cart !== null && $this->cart !== null ? [
                'id' => $this->cart->id,
                'status' => $this->cart->status->value,
                'version' => (int) $this->cart->version,
                'item_count' => $this->cart->relationLoaded('items') ? (int) $this->cart->items->sum('quantity') : null,
                'grand_total' => (float) $this->cart->grand_total,
                'currency' => $this->cart->currency,
                'items' => $this->cart->relationLoaded('items')
                    ? $this->cart->items->map(static fn ($item): array => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                    ])->all()
                    : [],
            ] : null,
            'order' => $order !== null && $this->order !== null ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status->value,
                'status_label' => $this->order->status->label(),
                'grand_total' => (float) $this->order->grand_total,
                'currency' => $this->order->currency,
                'placed_at' => $this->order->placed_at?->toIso8601String(),
            ] : null,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'events' => RecoveryEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
