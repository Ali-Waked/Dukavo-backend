<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Tenancy\TenantContext;

class CustomerTimelineService
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function getTimeline(Customer $customer, int $limit = 50): array
    {
        $tenant = $this->tenants->required();

        $timeline = collect();

        // Orders
        $orders = Order::query()
            ->where('customer_id', $customer->getKey())
            ->with(['items'])
            ->latest('placed_at')
            ->latest('created_at')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $timeline->push([
                'id' => 'order_'.$order->getKey(),
                'type' => 'order',
                'timestamp' => $order->placed_at?->toIso8601String() ?? $order->created_at?->toIso8601String(),
                'data' => [
                    'order_id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'status' => $order->status?->value ?? $order->status,
                    'payment_status' => $order->payment_status?->value ?? $order->payment_status,
                    'grand_total' => $order->grand_total,
                    'currency' => $order->currency,
                    'item_count' => $order->items->count(),
                ],
            ]);
        }

        // Conversations
        $conversations = Conversation::query()
            ->where('customer_id', $customer->getKey())
            ->with(['latestMessage'])
            ->latest('last_message_at')
            ->latest('created_at')
            ->limit($limit)
            ->get();

        foreach ($conversations as $conversation) {
            $timeline->push([
                'id' => 'conversation_'.$conversation->getKey(),
                'type' => 'conversation',
                'timestamp' => $conversation->last_message_at?->toIso8601String() ?? $conversation->created_at?->toIso8601String(),
                'data' => [
                    'conversation_id' => $conversation->getKey(),
                    'channel' => $conversation->channel,
                    'status' => is_object($conversation->status) ? ($conversation->status->value ?? null) : $conversation->status,
                    'subject' => $conversation->subject,
                    'display_name' => $conversation->display_name,
                ],
            ]);
        }

        $sorted = $timeline
            ->filter(fn ($item) => $item['timestamp'] !== null)
            ->sortByDesc('timestamp')
            ->values()
            ->take($limit);

        return $sorted->all();
    }

    public function getStats(Customer $customer): array
    {
        $orders = Order::query()->where('customer_id', $customer->getKey());

        $totalOrders = (clone $orders)->count();
        $currency = $this->tenants->tenant()?->currency;

        $paidOrders = (clone $orders)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->get();

        $totalSpent = $paidOrders->sum(fn ($o) => (float) $o->grand_total);
        $lastOrder = (clone $orders)->latest('placed_at')->first();

        $lastConversationAt = Conversation::query()
            ->where('customer_id', $customer->getKey())
            ->latest('last_message_at')
            ->first()?->last_message_at;

        $lastOrderAt = $lastOrder?->placed_at ?? $lastOrder?->created_at;

        $lastInteractionAt = collect([$lastOrderAt, $lastConversationAt])
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'orders_count' => $totalOrders,
            'paid_orders_count' => $paidOrders->count(),
            'total_spent' => round($totalSpent, 2),
            'average_order_value' => $paidOrders->isEmpty()
                ? 0.0
                : round($totalSpent / $paidOrders->count(), 2),
            'currency' => $currency ?? $lastOrder?->currency,
            'last_order_at' => $lastOrderAt?->toIso8601String(),
            'last_interaction_at' => $lastInteractionAt?->toIso8601String(),
        ];
    }
}
