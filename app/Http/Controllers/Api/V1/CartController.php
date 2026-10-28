<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\CancelCartRequest;
use App\Http\Requests\Cart\ConfirmCartRequest;
use App\Http\Requests\Cart\RemoveCartItemRequest;
use App\Http\Requests\Cart\ReopenCartRequest;
use App\Http\Requests\Cart\RequestCartConfirmationRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Requests\Cart\UpdateCartRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Services\Carts\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The conversation order builder API.
 *
 * Every money figure is produced by {@see CartService} from catalogue prices.
 * This controller only moves validated values between requests, the service and
 * API resources.
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Conversation $conversation): CartResource
    {
        return new CartResource($this->cartForConversation($conversation));
    }

    public function store(Conversation $conversation): JsonResponse
    {
        $cart = $this->carts->createForConversation($conversation);

        return ApiResponse::created('Cart ready.', new CartResource($cart));
    }

    public function update(UpdateCartRequest $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validated();
        $version = (int) $data['version'];
        unset($data['version']);

        $cart = $this->carts->updateDetails($this->cartForConversation($conversation), $data, $version);

        return ApiResponse::success('Cart updated.', new CartResource($cart));
    }

    public function addItem(AddCartItemRequest $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validated();
        $version = (int) $data['version'];
        unset($data['version']);

        $cart = $this->carts->addItem($this->cartForConversation($conversation), $data, $version);

        return ApiResponse::success('Item added.', new CartResource($cart));
    }

    public function updateItem(UpdateCartItemRequest $request, Conversation $conversation, CartItem $item): JsonResponse
    {
        $data = $request->validated();
        $version = (int) $data['version'];
        unset($data['version']);

        $cart = $this->carts->updateItem($this->cartForConversation($conversation), $item, $data, $version);

        return ApiResponse::success('Item updated.', new CartResource($cart));
    }

    public function removeItem(RemoveCartItemRequest $request, Conversation $conversation, CartItem $item): JsonResponse
    {
        $cart = $this->carts->removeItem(
            $this->cartForConversation($conversation),
            $item,
            (int) $request->validated('version'),
        );

        return ApiResponse::success('Item removed.', new CartResource($cart));
    }

    public function requestConfirmation(RequestCartConfirmationRequest $request, Conversation $conversation): JsonResponse
    {
        $cart = $this->carts->requestConfirmation(
            $this->cartForConversation($conversation),
            (int) $request->validated('version'),
            (bool) $request->validated('notify', false),
            $request->validated('message'),
        );

        return ApiResponse::success('Cart sent for confirmation.', new CartResource($cart));
    }

    public function reopen(ReopenCartRequest $request, Conversation $conversation): JsonResponse
    {
        $cart = $this->carts->reopen(
            $this->cartForConversation($conversation),
            (int) $request->validated('version'),
        );

        return ApiResponse::success('Cart reopened for editing.', new CartResource($cart));
    }

    public function cancel(CancelCartRequest $request, Conversation $conversation): JsonResponse
    {
        $cart = $this->carts->cancel(
            $this->cartForConversation($conversation),
            (int) $request->validated('version'),
        );

        return ApiResponse::success('Cart cancelled.', new CartResource($cart));
    }

    public function confirm(ConfirmCartRequest $request, Conversation $conversation): JsonResponse
    {
        $order = $this->carts->confirm(
            $this->cartForConversation($conversation),
            (int) $request->validated('version'),
            (float) $request->validated('expected_grand_total'),
        );

        return ApiResponse::created('Order created.', new OrderResource(
            $order->load(['items.variant', 'store', 'customer', 'statusHistory.user', 'conversation']),
        ));
    }

    private function cartForConversation(Conversation $conversation): Cart
    {
        $cart = $this->carts->latestForConversation($conversation);

        abort_if($cart === null, 404, 'This conversation does not have a cart yet.');

        return $cart;
    }
}
