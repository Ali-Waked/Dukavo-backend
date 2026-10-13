<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\TransitionOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Orders.
 *
 * Controllers here do no arithmetic at all. Every total is produced by
 * {@see OrderService} from catalogue prices, which is what makes it impossible for
 * a crafted request to change what a customer owes.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->with(['store', 'customer', 'items'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('store_id'), fn ($query) => $query->where('store_id', $request->integer('store_id')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('order_number', 'like', '%'.$request->string('search')->toString().'%'))
            ->when(
                $request->filled('from') && $request->filled('until'),
                fn ($query) => $query->placedBetween(
                    $request->date('from')->toDateString(),
                    $request->date('until')->toDateString(),
                ),
            )
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->payload());

        return ApiResponse::created('Order created.', new OrderResource($order));
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(['items.variant', 'store', 'customer', 'statusHistory.user']));
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        return new OrderResource($this->orders->update($order, $request->payload()));
    }

    /**
     * Advance the order. The request already refuses illegal transitions; the
     * service applies the side effects (stock, reservations, history).
     */
    public function transition(TransitionOrderRequest $request, Order $order): OrderResource
    {
        $order = $this->orders->transition(
            $order,
            OrderStatus::from($request->validated('status')),
            $request->validated('note'),
        );

        if ($request->validated('payment_status') === PaymentStatus::Paid->value) {
            $order = $this->orders->markPaid($order);
        }

        return new OrderResource($order);
    }

    /**
     * The states this order may move to, so the UI never offers an action the
     * server will refuse.
     */
    public function transitions(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'current' => $order->status->value,
                'allowed' => array_map(
                    fn (OrderStatus $status): array => [
                        'status' => $status->value,
                        'label' => $status->label(),
                    ],
                    $order->status->allowedTransitions(),
                ),
                'is_cancellable' => $order->isCancellable(),
            ],
        ]);
    }

    public function destroy(Order $order): Response
    {
        // Orders are cancelled, never deleted: the history is the audit trail.
        $this->orders->transition($order, OrderStatus::Cancelled, 'Deleted from the orders list');

        return response()->noContent();
    }
}
