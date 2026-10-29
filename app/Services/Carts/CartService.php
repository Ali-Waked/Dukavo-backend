<?php

declare(strict_types=1);

namespace App\Services\Carts;

use App\Enums\CartStatus;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Conversations\ConversationService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * The cart lifecycle behind the conversation order builder.
 *
 * A cart is priced on the server from the catalogue, never from browser input.
 * It holds no stock: confirmation hands the final lines to
 * {@see OrderService::create()}, which reserves inventory under a row lock and
 * produces the order through the existing order domain.
 *
 * Mutations are versioned. Two agents editing the same proposal cannot silently
 * overwrite one another; the second save gets a conflict and a fresh cart.
 */
class CartService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ConversationService $conversations,
        private readonly WhatsAppGateway $gateway,
    ) {}

    public function latestForConversation(Conversation $conversation): ?Cart
    {
        return Cart::query()
            ->where('conversation_id', $conversation->getKey())
            ->with($this->relations())
            ->latest('id')
            ->first();
    }

    /**
     * Reuse the open proposal for a thread, or start a fresh one.
     *
     * The conversation row is locked so two agents opening the builder at once
     * cannot create two active drafts for the same customer.
     */
    public function createForConversation(Conversation $conversation): Cart
    {
        return DB::transaction(function () use ($conversation): Cart {
            Conversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            $active = Cart::query()
                ->where('conversation_id', $conversation->getKey())
                ->whereIn('status', [CartStatus::Draft, CartStatus::PendingConfirmation])
                ->with($this->relations())
                ->latest('id')
                ->first();

            if ($active instanceof Cart) {
                return $active;
            }

            $store = $this->resolveStore($conversation);
            $customer = $this->resolveCustomer($conversation);

            $cart = Cart::query()->create([
                'conversation_id' => $conversation->getKey(),
                'store_id' => $store->getKey(),
                'customer_id' => $customer?->getKey(),
                'status' => CartStatus::Draft,
                'currency' => $store->currency,
                'version' => 1,
            ]);

            return $cart->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Cart $cart, array $attributes, int $version): Cart
    {
        return DB::transaction(function () use ($cart, $attributes, $version): Cart {
            $locked = $this->lock($cart, $version);
            $this->assertEditable($locked);

            $locked->fill(array_intersect_key($attributes, array_flip([
                'shipping_total', 'tax_rate', 'shipping_address', 'notes',
            ])));

            $this->recalculate($locked, enforceStock: false);
            $locked->forceFill(['version' => $locked->version + 1])->save();

            return $locked->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Cart $cart, array $attributes, int $version): Cart
    {
        return DB::transaction(function () use ($cart, $attributes, $version): Cart {
            $locked = $this->lock($cart, $version);
            $this->assertEditable($locked);

            $variant = $this->resolveVariant(
                $locked,
                $attributes['product_id'] ?? null,
                $attributes['product_variant_id'] ?? null,
            );

            $existing = CartItem::query()
                ->where('cart_id', $locked->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->first();

            $quantity = (int) ($attributes['quantity'] ?? 1) + (int) ($existing?->quantity ?? 0);
            $discount = (float) ($attributes['discount_total'] ?? 0) + (float) ($existing?->discount_total ?? 0);

            $this->assertStock($locked, $variant, $quantity);

            $item = $existing ?? new CartItem([
                'cart_id' => $locked->getKey(),
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
            ]);

            $item->fill([
                'name' => $this->lineName($variant),
                'sku' => $variant->sku ?? $variant->product->sku,
                'unit_price' => (float) $variant->price,
                'quantity' => $quantity,
                'discount_total' => $discount,
                'line_total' => 0,
            ])->save();

            $this->recalculate($locked);
            $locked->forceFill(['version' => $locked->version + 1])->save();

            return $locked->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(Cart $cart, CartItem $item, array $attributes, int $version): Cart
    {
        return DB::transaction(function () use ($cart, $item, $attributes, $version): Cart {
            $locked = $this->lock($cart, $version);
            $this->assertEditable($locked);
            $this->assertItemBelongs($locked, $item);

            $variant = $this->resolveVariant($locked, $item->product_id, $item->product_variant_id);
            $quantity = (int) $attributes['quantity'];

            $this->assertStock($locked, $variant, $quantity);

            $item->fill([
                'quantity' => $quantity,
                'discount_total' => (float) ($attributes['discount_total'] ?? $item->discount_total),
            ])->save();

            $this->recalculate($locked);
            $locked->forceFill(['version' => $locked->version + 1])->save();

            return $locked->load($this->relations());
        });
    }

    public function removeItem(Cart $cart, CartItem $item, int $version): Cart
    {
        return DB::transaction(function () use ($cart, $item, $version): Cart {
            $locked = $this->lock($cart, $version);
            $this->assertEditable($locked);
            $this->assertItemBelongs($locked, $item);

            $item->delete();
            $this->recalculate($locked, enforceStock: false);
            $locked->forceFill(['version' => $locked->version + 1])->save();

            return $locked->load($this->relations());
        });
    }

    /**
     * Freeze the proposal and optionally ask the customer to confirm it.
     *
     * The cart moves to `pending_confirmation` before the provider is called, so
     * a failed send leaves an honest "awaiting confirmation" state with the
     * provider error attached. A retry uses the same idempotency key and cannot
     * duplicate the message.
     */
    public function requestConfirmation(Cart $cart, int $version, bool $notify = false, ?string $message = null): Cart
    {
        $cart = DB::transaction(function () use ($cart, $version): Cart {
            $locked = $this->lock($cart, $version);

            if (! $locked->status->canTransitionTo(CartStatus::PendingConfirmation)) {
                if ($locked->status !== CartStatus::PendingConfirmation) {
                    throw BusinessRuleViolation::because(
                        'This cart can no longer be sent for confirmation.',
                        ['status' => $locked->status->value],
                        'cart_not_requestable',
                    );
                }
            }

            $this->assertHasItems($locked);
            $this->recalculate($locked);

            if ($locked->status === CartStatus::Draft) {
                $locked->forceFill([
                    'status' => CartStatus::PendingConfirmation,
                    'confirmation_requested_at' => now(),
                    'confirmation_error' => null,
                    'version' => $locked->version + 1,
                ])->save();
            }

            return $locked->load($this->relations());
        });

        if (! $notify || filled($cart->confirmation_message_id)) {
            return $cart;
        }

        $conversation = $cart->conversation;

        if ($conversation === null || $conversation->channel !== 'whatsapp') {
            throw BusinessRuleViolation::because(
                'Order confirmation messages can only be sent to a WhatsApp conversation.',
                ['channel' => $conversation?->channel],
                'cart_confirmation_channel_unsupported',
            );
        }

        $this->conversations->assertTextReplyAllowed($conversation);

        try {
            $providerId = $this->gateway->sendText(
                $conversation,
                $message ?: $this->confirmationMessage($cart),
                'cart:'.$cart->getKey().':confirmation:'.$cart->version,
            );
        } catch (BusinessRuleViolation $exception) {
            $cart->forceFill([
                'confirmation_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $cart->forceFill([
            'confirmation_sent_at' => now(),
            'confirmation_message_id' => $providerId,
            'confirmation_error' => null,
        ])->save();

        return $cart->load($this->relations());
    }

    /**
     * Convert a customer-confirmed proposal into a real pending order.
     *
     * Repeating a successful confirmation returns the existing order rather than
     * creating a second one. The unique `order_id` on carts is the database
     * backstop; locking the cart row serialises the common case.
     */
    public function confirm(Cart $cart, int $version, float $expectedGrandTotal): Order
    {
        /**
         * A stale total is a committed outcome, not a rollback: the refreshed
         * figures must survive so the agent can review what changed. Returning
         * the conflict outcome from the transaction and throwing afterwards
         * keeps those corrections while still answering 409.
         *
         * @var array{order?: Order, stale?: float} $outcome
         */
        $outcome = DB::transaction(function () use ($cart, $version, $expectedGrandTotal): array {
            $locked = Cart::query()->lockForUpdate()->findOrFail($cart->getKey());

            if ($locked->status === CartStatus::Converted && $locked->order_id !== null) {
                return ['order' => Order::query()
                    ->with(['items', 'store', 'customer', 'statusHistory'])
                    ->findOrFail($locked->order_id)];
            }

            $this->assertVersion($locked, $version);

            if ($locked->status !== CartStatus::PendingConfirmation) {
                throw BusinessRuleViolation::because(
                    'Only a cart awaiting customer confirmation can become an order.',
                    ['status' => $locked->status->value],
                    'cart_not_confirmable',
                );
            }

            $this->assertHasItems($locked);
            $this->recalculate($locked);

            $currentTotal = round((float) $locked->grand_total, 2);

            if ($currentTotal !== round($expectedGrandTotal, 2)) {
                return ['stale' => $currentTotal];
            }

            $locked->loadMissing('customer');
            $customer = $locked->customer;

            $order = $this->orders->create([
                'conversation_id' => $locked->conversation_id,
                'store_id' => $locked->store_id,
                'customer_id' => $locked->customer_id,
                'customer_name' => $customer?->name,
                'customer_phone' => $customer?->phone,
                'shipping_address' => $locked->shipping_address,
                'notes' => $locked->notes,
                'shipping_total' => (float) $locked->shipping_total,
                'tax_rate' => (float) $locked->tax_rate,
                'items' => $locked->items->map(fn (CartItem $item): array => [
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'discount_total' => (float) $item->discount_total,
                ])->all(),
            ], notify: false);

            if (abs((float) $order->grand_total - $currentTotal) > 0.001) {
                throw BusinessRuleViolation::because(
                    'Catalogue prices changed while the order was being placed. Review the refreshed cart.',
                    ['expected_grand_total' => $currentTotal, 'current_grand_total' => (float) $order->grand_total],
                    'cart_total_changed',
                );
            }

            $locked->forceFill([
                'status' => CartStatus::Converted,
                'order_id' => $order->getKey(),
                'version' => $locked->version + 1,
            ])->save();

            DB::afterCommit(fn () => $this->orders->announceCreated($order));

            return ['order' => $order];
        });

        if (isset($outcome['stale'])) {
            throw BusinessRuleViolation::because(
                sprintf('The cart total changed to %s. Review the updated proposal before confirming.', number_format($outcome['stale'], 2)),
                [
                    'expected_grand_total' => round($expectedGrandTotal, 2),
                    'current_grand_total' => $outcome['stale'],
                ],
                'cart_total_changed',
            );
        }

        return $outcome['order'];
    }

    public function reopen(Cart $cart, int $version): Cart
    {
        return DB::transaction(function () use ($cart, $version): Cart {
            $locked = $this->lock($cart, $version);

            if ($locked->status !== CartStatus::PendingConfirmation) {
                throw BusinessRuleViolation::because(
                    'Only a cart awaiting confirmation can be reopened for editing.',
                    ['status' => $locked->status->value],
                    'cart_not_reopenable',
                );
            }

            $locked->forceFill([
                'status' => CartStatus::Draft,
                'version' => $locked->version + 1,
            ])->save();

            return $locked->load($this->relations());
        });
    }

    public function cancel(Cart $cart, int $version): Cart
    {
        return DB::transaction(function () use ($cart, $version): Cart {
            $locked = $this->lock($cart, $version);

            if ($locked->status->isFinal()) {
                throw BusinessRuleViolation::because(
                    'This cart is already closed.',
                    ['status' => $locked->status->value],
                    'cart_already_closed',
                );
            }

            $locked->forceFill([
                'status' => CartStatus::Cancelled,
                'version' => $locked->version + 1,
            ])->save();

            return $locked->load($this->relations());
        });
    }

    private function lock(Cart $cart, int $version): Cart
    {
        $locked = Cart::query()->lockForUpdate()->findOrFail($cart->getKey());
        $this->assertVersion($locked, $version);

        return $locked;
    }

    private function assertVersion(Cart $cart, int $version): void
    {
        if ($cart->version !== $version) {
            throw BusinessRuleViolation::because(
                'This cart changed in another window. Refresh it before trying again.',
                ['current_version' => $cart->version],
                'cart_version_conflict',
            );
        }
    }

    private function assertEditable(Cart $cart): void
    {
        if (! $cart->isEditable()) {
            throw BusinessRuleViolation::because(
                'Reopen this cart before changing it.',
                ['status' => $cart->status->value],
                'cart_not_editable',
            );
        }
    }

    private function assertHasItems(Cart $cart): void
    {
        $cart->loadMissing('items');

        if ($cart->items->isEmpty()) {
            throw BusinessRuleViolation::because(
                'Add at least one product before creating an order.',
                [],
                'cart_empty',
            );
        }
    }

    private function assertItemBelongs(Cart $cart, CartItem $item): void
    {
        if ((int) $item->cart_id !== (int) $cart->getKey()) {
            abort(404);
        }
    }

    private function resolveVariant(Cart $cart, mixed $productId, mixed $variantId): ProductVariant
    {
        $product = Product::query()
            ->with('variants')
            ->findOrFail($productId);

        if ($product->status !== ProductStatus::Active) {
            throw BusinessRuleViolation::because(
                'This product is no longer available.',
                ['product_id' => $product->getKey()],
                'product_unavailable',
            );
        }

        if ($product->store_id !== null && (int) $product->store_id !== (int) $cart->store_id) {
            throw BusinessRuleViolation::because(
                'This product is not available in the cart’s store.',
                ['product_id' => $product->getKey(), 'store_id' => $cart->store_id],
                'product_not_in_store',
            );
        }

        $variant = $product->variants->firstWhere('id', (int) $variantId);

        if (! $variant instanceof ProductVariant) {
            throw BusinessRuleViolation::because(
                'The selected variant does not belong to this product.',
                ['product_id' => $product->getKey(), 'product_variant_id' => $variantId],
                'variant_product_mismatch',
            );
        }

        return $variant->setRelation('product', $product);
    }

    private function assertStock(Cart $cart, ProductVariant $variant, int $quantity): void
    {
        $available = $this->available($cart, $variant);

        if ($available < $quantity) {
            throw BusinessRuleViolation::because(
                sprintf('Only %d units are available in this store.', $available),
                [
                    'product_variant_id' => $variant->getKey(),
                    'available' => $available,
                    'requested' => $quantity,
                ],
                'insufficient_stock',
            );
        }
    }

    /**
     * Re-price every line from the catalogue and recompute the totals.
     */
    private function recalculate(Cart $cart, bool $enforceStock = true): void
    {
        $cart->loadMissing('items');

        $taxRate = max(0.0, min((float) $cart->tax_rate, 1));
        $shippingTotal = round((float) $cart->shipping_total, 2);

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($cart->items as $item) {
            $variant = $this->resolveVariant($cart, $item->product_id, $item->product_variant_id);
            $product = $variant->product;
            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                throw BusinessRuleViolation::because(
                    'Each cart line must have a quantity of at least 1.',
                    ['cart_item_id' => $item->getKey()],
                    'invalid_cart_quantity',
                );
            }

            if ($enforceStock) {
                $this->assertStock($cart, $variant, $quantity);
            }

            $unitPrice = (float) $variant->price;
            $lineSubtotal = round($unitPrice * $quantity, 2);
            $lineDiscount = round(min((float) $item->discount_total, $lineSubtotal), 2);
            $lineTaxable = max(0.0, $lineSubtotal - $lineDiscount);
            $lineTax = $product->is_taxable ? round($lineTaxable * $taxRate, 2) : 0.0;
            $lineTotal = round($lineTaxable + $lineTax, 2);

            $item->forceFill([
                'name' => $this->lineName($variant),
                'sku' => $variant->sku ?? $product->sku,
                'unit_price' => $unitPrice,
                'discount_total' => $lineDiscount,
                'line_total' => $lineTotal,
            ])->save();

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
        }

        $grandTotal = round($subtotal - $discountTotal + $taxTotal + $shippingTotal, 2);

        $cart->forceFill([
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => $shippingTotal,
            'grand_total' => $grandTotal,
        ])->save();

        $cart->unsetRelation('items');
    }

    private function available(Cart $cart, ProductVariant $variant): int
    {
        $inventory = Inventory::query()
            ->where('store_id', $cart->store_id)
            ->where('product_variant_id', $variant->getKey())
            ->first();

        return $inventory?->available() ?? 0;
    }

    private function lineName(ProductVariant $variant): string
    {
        return $variant->product->name.($variant->is_default ? '' : ' — '.$variant->name);
    }

    private function confirmationMessage(Cart $cart): string
    {
        $cart->loadMissing(['items', 'customer', 'store']);
        $currency = strtoupper((string) $cart->currency);
        $name = $cart->customer?->name ?: 'there';

        $lines = $cart->items->map(fn (CartItem $item): string => sprintf(
            '%d × %s — %s %s',
            $item->quantity,
            $item->name,
            number_format((float) $item->line_total, 2),
            $currency,
        ))->implode("\n");

        return trim(sprintf(
            "Hi %s,\n\nHere is your order from %s:\n\n%s\n\nSubtotal: %s %s\nDiscount: %s %s\nTax: %s %s\nShipping: %s %s\nTotal: %s %s\n\nReply YES to confirm and we will place your order.",
            $name,
            $cart->store?->name ?: 'our store',
            $lines,
            number_format((float) $cart->subtotal, 2),
            $currency,
            number_format((float) $cart->discount_total, 2),
            $currency,
            number_format((float) $cart->tax_total, 2),
            $currency,
            number_format((float) $cart->shipping_total, 2),
            $currency,
            number_format((float) $cart->grand_total, 2),
            $currency,
        ));
    }

    private function resolveStore(Conversation $conversation): Store
    {
        if ($conversation->store_id !== null) {
            return Store::query()->findOrFail($conversation->store_id);
        }

        return Store::query()->where('is_default', true)->first()
            ?? Store::query()->firstOrFail();
    }

    private function resolveCustomer(Conversation $conversation): ?Customer
    {
        if ($conversation->customer_id === null) {
            return null;
        }

        return Customer::query()->find($conversation->customer_id);
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'items.cart',
            'items.variant.product',
            'items.variant.inventories',
            'store',
            'customer',
            'conversation',
            'order',
        ];
    }
}
