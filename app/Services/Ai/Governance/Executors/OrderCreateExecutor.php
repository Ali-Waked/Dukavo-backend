<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Executors;

use App\Enums\AiActionClassification;
use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;

/**
 * Creates an order from catalogue data.
 *
 * The model may propose *what* and *how many*; it may never propose a price, a
 * tax rate or a shipping charge. Those are read from the catalogue and the
 * defaults, and the preview shown for approval is the same calculation the
 * order service performs. A price change between proposal and execution stops
 * the action so a person reviews it again.
 */
final class OrderCreateExecutor implements AiActionExecutor
{
    public function __construct(private readonly OrderService $orders) {}

    public function actionType(): string
    {
        return 'order.create';
    }

    public function classification(): AiActionClassification
    {
        return AiActionClassification::HighRisk;
    }

    public function requiredPermission(): string
    {
        return 'orders.create';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $store = $this->resolveStore($payload['store_id'] ?? null);
        $customer = $this->resolveCustomer($payload['customer_id'] ?? null);

        $items = $payload['items'] ?? null;

        if (! is_array($items) || $items === []) {
            throw BusinessRuleViolation::because('The proposed order must contain at least one item.');
        }

        if (count($items) > 100) {
            throw BusinessRuleViolation::because('The proposed order has too many lines.');
        }

        $lines = [];
        $subtotal = 0.0;
        $fingerprint = [];

        foreach ($items as $line) {
            if (! is_array($line)) {
                throw BusinessRuleViolation::because('Each proposed order line must be an object.');
            }

            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity < 1 || $quantity > 10000) {
                throw BusinessRuleViolation::because('Each order line must have a quantity between 1 and 10000.');
            }

            $variant = $this->resolveVariant($line['product_variant_id'] ?? null, $line['product_id'] ?? null);

            $product = $variant->product;

            if ($product === null || $product->status !== ProductStatus::Active) {
                throw BusinessRuleViolation::because('Every product in the proposed order must be active.');
            }

            $unitPrice = (float) $variant->price;
            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal += $lineTotal;

            $inventory = Inventory::query()
                ->where('store_id', $store->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->first();

            $available = $inventory instanceof Inventory ? $inventory->available() : 0;

            $lines[] = [
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'name' => $product->name.($variant->is_default ? '' : ' — '.$variant->name),
                'sku' => $variant->sku ?? $product->sku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'available' => $available,
            ];

            $fingerprint[] = [
                'variant_id' => $variant->getKey(),
                'price' => (string) $variant->price,
                'product_status' => $product->status->value,
            ];
        }

        $address = $this->sanitiseAddress($payload['shipping_address'] ?? null);

        return [
            'store_id' => $store->getKey(),
            'store_name' => $store->name,
            'currency' => $store->currency,
            'customer_id' => $customer?->getKey(),
            'customer_name' => $this->stringOrNull($payload['customer_name'] ?? null, 180) ?? $customer?->name,
            'customer_phone' => $this->stringOrNull($payload['customer_phone'] ?? null, 32) ?? $customer?->phone,
            'shipping_address' => $address,
            'notes' => $this->stringOrNull($payload['notes'] ?? null, 2000),
            'items' => $lines,
            'subtotal' => round($subtotal, 2),
            'shipping_total' => 0.0,
            'tax_total' => 0.0,
            'grand_total' => round($subtotal, 2),
            'fingerprint' => hash('sha256', json_encode([
                'store' => $store->getKey(),
                'store_status' => $store->status->value,
                'customer' => $customer?->getKey(),
                'items' => $fingerprint,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function revalidate(array $payload): array
    {
        $fresh = $this->resolve([
            'store_id' => $payload['store_id'] ?? null,
            'customer_id' => $payload['customer_id'] ?? null,
            'customer_name' => $payload['customer_name'] ?? null,
            'customer_phone' => $payload['customer_phone'] ?? null,
            'shipping_address' => $payload['shipping_address'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'items' => array_map(
                static fn (array $line): array => [
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'product_id' => $line['product_id'] ?? null,
                    'quantity' => $line['quantity'] ?? null,
                ],
                is_array($payload['items'] ?? null) ? $payload['items'] : [],
            ),
        ]);

        if (! hash_equals((string) ($payload['fingerprint'] ?? ''), (string) $fresh['fingerprint'])) {
            throw BusinessRuleViolation::because(
                'The catalogue, price or customer changed after this order was proposed. Review it and approve it again.',
            );
        }

        $fresh['missing_stock'] = array_values(array_filter(
            $fresh['items'],
            static fn (array $line): bool => (int) $line['available'] < (int) $line['quantity'],
        ));

        if ($fresh['missing_stock'] !== []) {
            throw BusinessRuleViolation::because('One or more proposed order lines no longer have enough stock.');
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, User $actor, string $idempotencyKey): array
    {
        $order = $this->orders->create([
            'store_id' => $payload['store_id'],
            'customer_id' => $payload['customer_id'] ?? null,
            'customer_name' => $payload['customer_name'] ?? null,
            'customer_phone' => $payload['customer_phone'] ?? null,
            'shipping_address' => $payload['shipping_address'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'shipping_total' => 0,
            'tax_rate' => 0,
            'items' => array_map(
                static fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'quantity' => (int) $line['quantity'],
                    'discount_total' => 0,
                ],
                $payload['items'],
            ),
        ]);

        return [
            'order_id' => $order->getKey(),
            'order_number' => $order->order_number,
            'grand_total' => (float) $order->grand_total,
            'currency' => $order->currency,
            'item_count' => (int) $order->items->sum('quantity'),
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function resolveStore(mixed $storeId): Store
    {
        $store = Store::query()->find((int) $storeId);

        if (! $store instanceof Store || $store->status !== StoreStatus::Active) {
            throw BusinessRuleViolation::because('The proposed store does not exist or is not active.');
        }

        return $store;
    }

    private function resolveCustomer(mixed $customerId): ?Customer
    {
        if ($customerId === null || $customerId === '' || (int) $customerId === 0) {
            return null;
        }

        $customer = Customer::query()->find((int) $customerId);

        if (! $customer instanceof Customer) {
            throw BusinessRuleViolation::because('The proposed customer does not exist in this workspace.');
        }

        return $customer;
    }

    private function resolveVariant(mixed $variantId, mixed $productId): ProductVariant
    {
        $variant = $variantId !== null && $variantId !== ''
            ? ProductVariant::query()->with('product')->find((int) $variantId)
            : null;

        if ($variant instanceof ProductVariant) {
            return $variant;
        }

        if ($productId !== null && $productId !== '') {
            $product = Product::query()->with('variants')->find((int) $productId);
            $default = $product?->defaultVariant();

            if ($default instanceof ProductVariant) {
                return $default->load('product');
            }
        }

        throw BusinessRuleViolation::because('The proposed product or variant does not exist in this workspace.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitiseAddress(mixed $address): ?array
    {
        if (! is_array($address) || $address === []) {
            return null;
        }

        $allowed = [
            'contact_name' => 180,
            'line_one' => 255,
            'line_two' => 255,
            'city' => 120,
            'state' => 120,
            'postal_code' => 20,
            'country' => 2,
        ];

        $clean = [];

        foreach ($allowed as $key => $max) {
            $value = $this->stringOrNull($address[$key] ?? null, $max);

            if ($value !== null) {
                $clean[$key] = $key === 'country' ? Str::upper($value) : $value;
            }
        }

        return $clean === [] ? null : $clean;
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
