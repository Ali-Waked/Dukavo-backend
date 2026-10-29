<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Executors;

use App\Enums\AiActionClassification;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use App\Services\Orders\OrderService;

/**
 * Moves an order to a new status.
 *
 * The transition machine and its stock effects remain owned by
 * {@see OrderService}. The executor only proves that the proposed move is legal
 * for the exact order state a person reviewed, and refuses if that state changed
 * between approval and execution — a stale "cancel" or "ship" must never be
 * applied to an order that has moved on.
 */
final class OrderTransitionExecutor implements AiActionExecutor
{
    public function __construct(private readonly OrderService $orders) {}

    public function actionType(): string
    {
        return 'order.transition';
    }

    public function classification(): AiActionClassification
    {
        return AiActionClassification::HighRisk;
    }

    public function requiredPermission(): string
    {
        return 'orders.update';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $order = $this->resolveOrder($payload['order_id'] ?? null);
        $status = OrderStatus::tryFrom((string) ($payload['status'] ?? ''));

        if (! $status instanceof OrderStatus) {
            throw BusinessRuleViolation::because('The proposed order status is not valid.');
        }

        if (! $order->status->canTransitionTo($status)) {
            throw BusinessRuleViolation::because(sprintf(
                'Order %s cannot move from %s to %s.',
                $order->order_number,
                $order->status->label(),
                $status->label(),
            ));
        }

        return [
            'order_id' => $order->getKey(),
            'order_number' => $order->order_number,
            'from_status' => $order->status->value,
            'to_status' => $status->value,
            'from_status_label' => $order->status->label(),
            'to_status_label' => $status->label(),
            'grand_total' => (float) $order->grand_total,
            'currency' => $order->currency,
            'note' => $this->stringOrNull($payload['note'] ?? null, 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function revalidate(array $payload): array
    {
        $fresh = $this->resolve([
            'order_id' => $payload['order_id'] ?? null,
            'status' => $payload['to_status'] ?? null,
            'note' => $payload['note'] ?? null,
        ]);

        if ((string) $fresh['from_status'] !== (string) ($payload['from_status'] ?? '')) {
            throw BusinessRuleViolation::because(
                'The order changed after this transition was proposed. Review its current status and approve it again.',
            );
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, User $actor, string $idempotencyKey): array
    {
        $order = Order::query()->findOrFail((int) $payload['order_id']);
        $status = OrderStatus::from((string) $payload['to_status']);

        $updated = $this->orders->transition($order, $status, $payload['note'] ?? null);

        return [
            'order_id' => $updated->getKey(),
            'order_number' => $updated->order_number,
            'from_status' => $payload['from_status'],
            'to_status' => $updated->status->value,
            'status' => $updated->status->label(),
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function resolveOrder(mixed $orderId): Order
    {
        $order = Order::query()->find((int) $orderId);

        if (! $order instanceof Order) {
            throw BusinessRuleViolation::because('The proposed order does not exist in this workspace.');
        }

        return $order;
    }

    private function stringOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
