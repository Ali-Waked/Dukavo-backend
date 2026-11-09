<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class OrderDelivered implements AutomationEvent
{
    use Dispatchable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        private int $tenantId,
        private ?int $storeId,
        private array $context,
        private string $eventKey,
    ) {}

    public static function fromOrder(Order $order, OrderStatusHistory $history, ?string $note = null): self
    {
        $order->loadMissing('customer');

        return new self(
            tenantId: (int) $order->tenant_id,
            storeId: $order->store_id !== null ? (int) $order->store_id : null,
            context: [
                'order_id' => (int) $order->getKey(),
                'order_number' => (string) $order->order_number,
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'grand_total' => (float) $order->grand_total,
                'currency' => (string) $order->currency,
                'store_id' => $order->store_id !== null ? (int) $order->store_id : null,
                'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
                'customer_name' => (string) ($order->customer_name ?: $order->customer?->name ?: ''),
                'customer_email' => (string) ($order->customer_email ?: $order->customer?->email ?: ''),
                'note' => (string) ($note ?? ''),
            ],
            eventKey: 'order.delivered:'.$order->getKey().':'.$history->getKey(),
        );
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function storeId(): ?int
    {
        return $this->storeId;
    }

    public function trigger(): AutomationTrigger
    {
        return AutomationTrigger::OrderDelivered;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function dedupeKey(): string
    {
        return $this->eventKey;
    }
}
