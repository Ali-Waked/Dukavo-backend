<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\InventoryMovementType;
use App\Enums\NotificationSeverity;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Automation\OrderCreated as AutomationOrderCreated;
use App\Events\Automation\OrderStatusChanged as AutomationOrderStatusChanged;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Services\Recovery\RecoveryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Order creation and pricing.
 *
 * Money rule: the client may propose quantities, a discount and a tax rate, but
 * it may never send a unit price or a total. Prices are read from the catalogue
 * and every figure is computed here, so a tampered payload cannot change what a
 * customer is charged.
 */
class OrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly TenantContext $tenants,
        private readonly NotificationDispatcher $notifications,
        private readonly RecoveryService $recoveries,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, bool $notify = true): Order
    {
        $tenant = $this->tenants->required();
        $store = $this->resolveStore($attributes['store_id'] ?? null);

        $order = DB::transaction(function () use ($tenant, $store, $attributes): Order {
            $customer = isset($attributes['customer_id'])
                ? Customer::query()->findOrFail($attributes['customer_id'])
                : null;

            $order = Order::query()->create([
                'tenant_id' => $tenant->getKey(),
                'store_id' => $store->getKey(),
                'customer_id' => $customer?->getKey(),
                'conversation_id' => $attributes['conversation_id'] ?? null,
                'order_number' => $this->nextOrderNumber($store),
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'currency' => $attributes['currency'] ?? $store->currency,
                'customer_name' => $attributes['customer_name'] ?? $customer?->name,
                'customer_phone' => $attributes['customer_phone'] ?? $customer?->phone,
                'shipping_address' => $attributes['shipping_address'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            // Server-owned timestamps are set explicitly rather than mass
            // assigned: `placed_at` is not fillable, and adding it would let a
            // crafted payload backdate an order out of every revenue report.
            $order->forceFill(['placed_at' => now()])->save();

            $this->recalculate($order, $attributes['items'] ?? [], [
                'shipping_total' => $attributes['shipping_total'] ?? 0,
                'tax_rate' => $attributes['tax_rate'] ?? 0,
                'reserve_stock' => true,
            ]);

            $this->recordStatusHistory($order, null, OrderStatus::Pending, 'Order created');

            $customer?->forceFill(['last_order_at' => now()])->save();

            return $order->load(['items', 'store', 'customer', 'statusHistory']);
        });

        $this->recoveries->attributeOrder($order);

        if ($notify) {
            $this->announceCreated($order);
        }

        return $order;
    }

    /**
     * Emit the "order created" notification for an order that has already been
     * committed. Cart conversion defers this until its wider transaction
     * commits, so a rolled-back proposal can never announce an order that does
     * not exist.
     */
    public function announceCreated(Order $order): void
    {
        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_ORDER_CREATED,
            tenantId: (int) $order->tenant_id,
            dedupeKey: 'tenant.order.created:'.$order->getKey(),
            data: [
                'order_number' => $order->order_number,
                'customer' => $order->customer_name ?: 'A customer',
                'total' => $this->formatMoney($order->grand_total, $order->currency),
            ],
            actionUrl: '/orders/'.$order->order_number,
            actorId: auth()->id(),
        ));

        event(AutomationOrderCreated::fromOrder($order));
    }

    /**
     * Replace the lines of an order that has not started fulfilment and
     * recompute every total.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Order $order, array $attributes): Order
    {
        if (! $order->isEditable()) {
            throw BusinessRuleViolation::because('A '.strtolower($order->status->label()).' order can no longer be edited.');
        }

        return DB::transaction(function () use ($order, $attributes): Order {
            $shippingTotal = array_key_exists('shipping_total', $attributes)
                ? (float) $attributes['shipping_total']
                : (float) $order->shipping_total;
            $taxRate = array_key_exists('tax_rate', $attributes)
                ? (float) $attributes['tax_rate']
                : $this->effectiveTaxRate($order);

            if (array_key_exists('items', $attributes)) {
                $this->releaseReservations($order);
                $order->items()->delete();

                $this->recalculate($order, $attributes['items'], [
                    'shipping_total' => $shippingTotal,
                    'tax_rate' => $taxRate,
                    'reserve_stock' => true,
                ]);
                unset($attributes['items']);
            } elseif (array_key_exists('shipping_total', $attributes) || array_key_exists('tax_rate', $attributes)) {
                $this->recalculateStoredTotals($order, $shippingTotal, $taxRate);
            }

            $order->fill(array_intersect_key($attributes, array_flip([
                'customer_name', 'customer_phone', 'shipping_address', 'notes',
            ])))->save();

            return $order->load(['items', 'store', 'customer', 'statusHistory']);
        });
    }

    /**
     * Build order lines from catalogue data and recompute the totals.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array{shipping_total?: float, tax_rate?: float, reserve_stock?: bool}  $options
     */
    private function recalculate(Order $order, array $items, array $options = []): void
    {
        if ($items === []) {
            throw BusinessRuleViolation::because('An order must contain at least one item.');
        }

        $shippingTotal = round((float) ($options['shipping_total'] ?? 0), 2);
        $taxRate = max(0.0, min((float) ($options['tax_rate'] ?? 0), 1));
        $reserveStock = (bool) ($options['reserve_stock'] ?? false);

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($items as $line) {
            $variant = $this->resolveVariant($line['product_id'] ?? null, $line['product_variant_id'] ?? null);
            $product = $variant->product;

            $quantity = (int) ($line['quantity'] ?? 1);

            if ($quantity < 1) {
                throw BusinessRuleViolation::because('Each order line must have a quantity of at least 1.');
            }

            // Unit price always comes from the catalogue, never the request.
            $unitPrice = (float) $variant->price;
            $lineSubtotal = round($unitPrice * $quantity, 2);

            // A line discount may not exceed the line itself.
            $lineDiscount = round(min((float) ($line['discount_total'] ?? 0), $lineSubtotal), 2);
            $lineTaxable = max(0.0, $lineSubtotal - $lineDiscount);
            $lineTax = $product->is_taxable ? round($lineTaxable * $taxRate, 2) : 0.0;
            $lineTotal = round($lineTaxable + $lineTax, 2);

            // Catalogue costs change. Capture the cost used for this line now so
            // profit reports never rewrite history with a later supplier price.
            $unitCost = $this->historicalUnitCost($product, $variant, (string) $order->currency);

            OrderItem::query()->create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->getKey(),
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'name' => $product->name.($variant->is_default ? '' : ' — '.$variant->name),
                'sku' => $variant->sku ?? $product->sku,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'cost_currency' => $unitCost === null ? null : strtoupper((string) $product->currency),
                'cost_captured_at' => $unitCost === null ? null : now(),
                'quantity' => $quantity,
                'discount_total' => $lineDiscount,
                'tax_total' => $lineTax,
                'line_total' => $lineTotal,
            ]);

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;

            if ($reserveStock) {
                $this->reserveStockForVariant($order, $variant, $quantity);
            }
        }

        $grandTotal = round($subtotal - $discountTotal + $taxTotal + $shippingTotal, 2);

        $order->forceFill([
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => $shippingTotal,
            'grand_total' => $grandTotal,
        ])->save();
    }

    /**
     * The rate already applied to this order, recovered from its stored lines.
     *
     * Orders do not keep the submitted rate, only the tax amount it produced, so
     * editing the lines without resending the rate must not silently reset tax
     * to zero. A line whose net amount and tax are both known is enough to
     * recover the ratio; rounding may make it a fraction off, which is why the
     * resulting amounts are recomputed from the rate rather than copied.
     */
    private function effectiveTaxRate(Order $order): float
    {
        foreach ($order->items()->get() as $item) {
            $lineSubtotal = round((float) $item->unit_price * (int) $item->quantity, 2);
            $lineDiscount = round(min((float) $item->discount_total, $lineSubtotal), 2);
            $net = round($lineSubtotal - $lineDiscount, 2);
            $tax = (float) $item->tax_total;

            if ($net > 0 && $tax > 0) {
                return max(0.0, min($tax / $net, 1.0));
            }
        }

        return 0.0;
    }

    /**
     * Recompute totals without touching the order's lines or stock.
     *
     * Changing shipping or the tax rate is a totals-only edit, so the stored
     * unit prices, quantities and cost snapshots must survive it untouched.
     */
    private function recalculateStoredTotals(Order $order, float $shippingTotal, float $taxRate): void
    {
        $shippingTotal = round($shippingTotal, 2);
        $taxRate = max(0.0, min($taxRate, 1));

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($order->items()->with('product')->get() as $item) {
            $lineSubtotal = round((float) $item->unit_price * (int) $item->quantity, 2);
            $lineDiscount = round(min((float) $item->discount_total, $lineSubtotal), 2);
            $lineNet = max(0.0, $lineSubtotal - $lineDiscount);

            $product = $item->product;
            $taxable = $product?->is_taxable ?? ((float) $item->tax_total > 0);
            $lineTax = $taxable ? round($lineNet * $taxRate, 2) : 0.0;

            $item->forceFill([
                'discount_total' => $lineDiscount,
                'tax_total' => $lineTax,
                'line_total' => round($lineNet + $lineTax, 2),
            ])->save();

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
        }

        $order->forceFill([
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => $shippingTotal,
            'grand_total' => round($subtotal - $discountTotal + $taxTotal + $shippingTotal, 2),
        ])->save();
    }

    /**
     * The catalogue unit cost, but only when it is trustworthy for this sale.
     *
     * A cost is unusable when it is zero/negative, or when the catalogue stores
     * it in a different currency than the order. Returning null keeps the gap
     * visible in profit reports instead of pretending the line was free.
     */
    private function historicalUnitCost(Product $product, ProductVariant $variant, string $orderCurrency): ?float
    {
        $catalogCurrency = strtoupper(trim((string) $product->currency));

        if ($catalogCurrency === '' || $catalogCurrency !== strtoupper(trim($orderCurrency))) {
            return null;
        }

        $cost = (float) $variant->cost;

        if ($cost <= 0) {
            $cost = (float) $product->cost;
        }

        if ($cost <= 0 || ! is_finite($cost)) {
            return null;
        }

        return round($cost, 2);
    }

    /**
     * Reserve stock for an order line so concurrent orders cannot oversell.
     *
     * The availability check lives inside {@see InventoryService::reserve()},
     * which performs it under a row lock. Repeating the check here would only
     * reintroduce the race it exists to prevent.
     */
    private function reserveStockForVariant(Order $order, ProductVariant $variant, int $quantity): void
    {
        $inventory = $this->inventory->findOrCreate($order->store, $variant);

        $this->inventory->reserve(
            $inventory,
            $quantity,
            $order->getMorphClass(),
            $order->getKey(),
        );
    }

    private function releaseReservations(Order $order): void
    {
        $lines = $order->items()->get()->map(fn (OrderItem $item): array => [
            'product_variant_id' => $item->product_variant_id,
            'store_id' => $order->store_id,
            'quantity' => $item->quantity,
        ])->all();

        $this->inventory->release($order->store, $lines, $order->getMorphClass(), $order->getKey());
    }

    /**
     * Deduct the physical quantity when an order ships: the reservation becomes
     * a real withdrawal from the ledger.
     */
    public function consumeStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = $this->inventory->findOrCreate($order->store, $item->variant);

            $this->inventory->consume(
                $inventory,
                $item->quantity,
                'Order '.$order->order_number,
                $order->getMorphClass(),
                $order->getKey(),
                auth()->id(),
                'order',
                'order:'.$order->getKey().':ship:'.$item->getKey(),
            );
        }
    }

    /**
     * Move an order to a new status, applying the side effects that belong to
     * the transition: stock, reservations, payment and the history trail.
     */
    public function transition(Order $order, OrderStatus $status, ?string $note = null): Order
    {
        $from = $order->status;
        $history = null;

        $order = DB::transaction(function () use ($order, $status, $note, &$from, &$history): Order {
            // Two operators can click "ship" and "cancel" at the same moment.
            // Lock the row and re-read the status inside the transaction so only
            // one transition wins; the other is evaluated against the new state
            // instead of applying side effects from a stale one. Refreshing the
            // caller's own model keeps the previous contract: callers that reuse
            // it see the new status rather than a stale copy.
            Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $order->refresh();
            $from = $order->status;

            if ($from === $status) {
                return $order->load(['items.variant', 'store', 'customer', 'statusHistory']);
            }

            if (! $from->canTransitionTo($status)) {
                throw BusinessRuleViolation::because(sprintf(
                    'An order cannot move from %s to %s.',
                    $from->label(),
                    $status->label(),
                ));
            }

            $attributes = ['status' => $status];

            if ($status === OrderStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
            }

            if ($status === OrderStatus::Delivered && $order->payment_status === PaymentStatus::Unpaid) {
                $attributes['payment_status'] = PaymentStatus::Paid;
            }

            $order->forceFill($attributes)->save();

            // Stock leaves the warehouse when the goods actually ship. A
            // confirmed or processing order has only reserved units, so
            // cancelling it releases the reservation and must not manufacture
            // a return movement for stock that never left.
            if ($status === OrderStatus::Shipped && $from->consumesStock()) {
                $this->consumeStock($order);
            }

            if ($status === OrderStatus::Cancelled) {
                if ($from === OrderStatus::Shipped) {
                    $this->restock($order);
                }

                $this->releaseReservations($order);
            }

            $history = $this->recordStatusHistory($order, $from, $status, $note);

            return $order->load(['items.variant', 'store', 'customer', 'statusHistory']);
        });

        if ($status === OrderStatus::Cancelled) {
            $this->recoveries->releaseOrder($order);
        }

        // Low stock is re-checked after the stock side effects committed: a
        // shipment that drew a line below its threshold should raise one alert
        // once, not once per stock movement inside the transaction.
        if (in_array($status, [OrderStatus::Shipped, OrderStatus::Cancelled], true)) {
            $this->inventory->evaluateLowStockForOrder($order);
        }

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_ORDER_STATUS_CHANGED,
            tenantId: (int) $order->tenant_id,
            dedupeKey: 'tenant.order.status_changed:'.$order->getKey().':'.$status->value,
            data: [
                'order_number' => $order->order_number,
                'status' => $status->label(),
                'from' => $from->label(),
                'to' => $status->label(),
            ],
            actionUrl: '/orders/'.$order->order_number,
            actorId: auth()->id(),
            severity: $this->severityForStatus($status),
        ));

        if ($history instanceof OrderStatusHistory) {
            event(AutomationOrderStatusChanged::fromOrder($order, $from, $status, $history, $note));
        }

        return $order;
    }

    /**
     * Record a payment against an order. Only the transition unpaid → paid is
     * meaningful here; refunds are handled by the payment ledger in a future
     * iteration of the billing module.
     */
    public function markPaid(Order $order): Order
    {
        $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

        return $order;
    }

    /**
     * Put stock back when an order is cancelled.
     */
    public function restock(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = $this->inventory->findOrCreate($order->store, $item->variant);

            $this->inventory->applyMovement(
                $inventory,
                InventoryMovementType::ReturnIn,
                $item->quantity,
                'Order '.$order->order_number.' cancelled',
                $order->getMorphClass(),
                $order->getKey(),
                auth()->id(),
                source: 'order_cancelled',
                idempotencyKey: 'order:'.$order->getKey().':cancel:'.$item->getKey(),
            );
        }
    }

    private function recordStatusHistory(Order $order, ?OrderStatus $from, OrderStatus $to, ?string $note = null): OrderStatusHistory
    {
        return OrderStatusHistory::query()->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->getKey(),
            'user_id' => auth()->id(),
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function resolveVariant(mixed $productId, mixed $variantId): ProductVariant
    {
        if ($variantId !== null && $variantId !== '') {
            return ProductVariant::query()->with('product')->findOrFail($variantId);
        }

        $product = Product::query()->with('variants')->findOrFail($productId);
        $variant = $product->defaultVariant();

        if (! $variant instanceof ProductVariant) {
            throw BusinessRuleViolation::because('This product has no purchasable variant.');
        }

        return $variant->load('product');
    }

    private function resolveStore(mixed $storeId): Store
    {
        if ($storeId === null || $storeId === '') {
            // A tenant always has a default store, so an order is never orphaned.
            return Store::query()->where('is_default', true)->first()
                ?? Store::query()->firstOrFail();
        }

        return Store::query()->findOrFail($storeId);
    }

    /**
     * Human-readable, tenant-scoped order number.
     *
     * The store row is locked first so that two simultaneous checkouts in the
     * same store queue up instead of both claiming the same sequence number.
     * Orders in different stores are numbered independently, which is fine for
     * an internal reference and keeps the lock short.
     */
    /**
     * A cancelled order is a warning the merchant should look at; a delivered
     * one is good news. Everything else is neutral.
     */
    private function severityForStatus(OrderStatus $status): NotificationSeverity
    {
        return match ($status) {
            OrderStatus::Cancelled => NotificationSeverity::Warning,
            OrderStatus::Delivered => NotificationSeverity::Success,
            default => NotificationSeverity::Info,
        };
    }

    private function formatMoney(mixed $amount, ?string $currency): string
    {
        return number_format((float) $amount, 2).' '.strtoupper((string) $currency);
    }

    private function nextOrderNumber(Store $store): string
    {
        $padding = (int) config('dukavo.order_number.padding', 6);
        $prefix = Str::upper((string) config('dukavo.order_number.prefix', 'ORD'));

        // Order numbers are unique per tenant, not per store, so the lock has to
        // be taken on the tenant row. Locking the store serialises numbering only
        // for orders of that one store and would let two stores issue the same
        // number.
        Tenant::query()->lockForUpdate()->findOrFail($store->tenant_id);

        $sequence = (Order::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $store->tenant_id)
            ->max('id') ?? 0) + 1;

        return sprintf('%s-%0'.$padding.'d', $prefix, $sequence);
    }
}
