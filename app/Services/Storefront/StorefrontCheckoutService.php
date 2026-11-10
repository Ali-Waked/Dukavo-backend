<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentSessionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public checkout.
 *
 * This service never prices anything itself: it validates that the selected
 * variants may be sold by this store, then hands them to {@see OrderService},
 * which reads catalogue prices and reserves stock under a row lock. Shipping is
 * the only extra calculation, and it also comes from the server-side zone table.
 */
class StorefrontCheckoutService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ShippingService $shipping,
        private readonly PaymentService $payments,
        private readonly PaymentSessionService $sessions,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function checkout(Store $store, array $data): Order
    {
        if ($this->tenants->id() !== (int) $store->tenant_id) {
            throw BusinessRuleViolation::because('This storefront is not available.');
        }

        $key = trim((string) $data['idempotency_key']);

        $existing = $this->findExistingOrder($store, $key);

        if ($existing instanceof Order) {
            return $existing;
        }

        $method = PaymentMethod::from((string) $data['payment_method']);
        $methodCode = isset($data['payment_method_code']) ? (string) $data['payment_method_code'] : null;

        // Resolve every refusal before any row is written. For online payment
        // this is also the exact rail the provider will be asked to charge, so
        // the browser cannot substitute a cheaper or unsupported source.
        if ($method === PaymentMethod::Online) {
            $this->payments->onlineOption($store, $methodCode);
        } else {
            $this->payments->assertAvailable($store, $method);
        }

        try {
            $order = DB::transaction(function () use ($store, $data, $key, $method): Order {
                $lines = $this->validateLines($store, $data['items'] ?? []);
                $merchandiseTotal = 0.0;

                foreach ($lines as $line) {
                    $merchandiseTotal += (float) $line['unit_price'] * (int) $line['quantity'];
                }

                $address = $this->shippingAddress($data, $customerName = (string) $data['customer_name']);
                $quote = $this->shipping->quote(
                    $store,
                    $address['city'] ?? null,
                    $data['area'] ?? null,
                    $merchandiseTotal,
                );

                $customer = $this->resolveCustomer($store, [
                    'name' => $customerName,
                    'email' => $data['customer_email'] ?? null,
                    'phone' => $data['customer_phone'] ?? null,
                    'locale' => (string) ($this->tenants->required()->locale ?? 'en'),
                ]);

                $this->rememberAddress($customer, $address);

                $order = $this->orders->create([
                    'store_id' => $store->getKey(),
                    'customer_id' => $customer->getKey(),
                    'customer_name' => $customerName,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'customer_email' => $data['customer_email'] ?? null,
                    'shipping_address' => $address,
                    'notes' => $data['notes'] ?? null,
                    'items' => array_map(
                        fn (array $line): array => [
                            'product_variant_id' => $line['product_variant_id'],
                            'quantity' => $line['quantity'],
                        ],
                        $lines,
                    ),
                    'shipping_total' => 0,
                    'tax_rate' => 0,
                    'payment_method' => $method,
                    'tracking_token' => $this->trackingToken(),
                    'shipping_zone_id' => $quote['zone']?->getKey(),
                    'idempotency_key' => $key,
                    'source' => 'storefront',
                ], notify: false);

                // `create()` prices the lines; delivery is applied afterwards so
                // the fee always covers the final merchandise total, which is
                // what the free-shipping threshold is measured against.
                return $this->orders->update($order, ['shipping_total' => $quote['fee']]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical checkouts raced past the initial lookup. The unique
            // key is the arbiter; the loser returns the winner's order.
            $existing = $this->findExistingOrder($store, $key);

            if ($existing instanceof Order) {
                return $existing;
            }

            throw $exception;
        }

        // Announce only after the order transaction committed, so a rolled-back
        // attempt can never notify the merchant about an order that does not
        // exist. A replayed idempotency key returns above and does not announce
        // twice.
        $this->orders->announceCreated($order);

        // The order is committed first. A provider refusal then leaves an
        // order with a failed attempt that the customer can retry, instead of
        // rolling back a valid order or pretending the charge succeeded.
        if ($method === PaymentMethod::Online) {
            try {
                $this->sessions->start($order, $methodCode);
            } catch (BusinessRuleViolation $exception) {
                report($exception);
            }

            $order->loadMissing('latestPayment');
        }

        return $order;
    }

    public function findByTrackingToken(Store $store, string $token): Order
    {
        return Order::query()
            ->where('store_id', $store->getKey())
            ->where('tracking_token', $token)
            ->with(['items', 'store', 'latestPayment.provider'])
            ->firstOrFail();
    }

    private function findExistingOrder(Store $store, string $key): ?Order
    {
        $order = Order::query()->where('idempotency_key', $key)->first();

        if (! $order instanceof Order) {
            return null;
        }

        if ((int) $order->store_id !== (int) $store->getKey()) {
            throw BusinessRuleViolation::because(
                'This checkout reference was already used in another store. Please start a new checkout.'
            );
        }

        return $order->loadMissing(['items', 'store']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{product_variant_id: int, quantity: int, unit_price: float}>
     */
    private function validateLines(Store $store, array $items): array
    {
        if ($items === []) {
            throw BusinessRuleViolation::because('Your cart is empty.');
        }

        $lines = [];

        foreach ($items as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity < 1 || $quantity > 1000) {
                throw BusinessRuleViolation::because('Each item needs a quantity between 1 and 1000.');
            }

            $variant = $this->resolveVariant($line);
            $product = $variant->product;

            if ($product === null || $product->status !== ProductStatus::Active) {
                throw BusinessRuleViolation::because('One of the items in your cart is no longer available.');
            }

            if ($product->store_id !== null && (int) $product->store_id !== (int) $store->getKey()) {
                throw BusinessRuleViolation::because('One of the items in your cart is not sold by this store.');
            }

            if (strtoupper((string) $product->currency) !== strtoupper((string) $store->currency)) {
                throw BusinessRuleViolation::because(
                    'One of the items in your cart is priced in a different currency and cannot be ordered here.'
                );
            }

            $lines[] = [
                'product_variant_id' => (int) $variant->getKey(),
                'quantity' => $quantity,
                'unit_price' => (float) $variant->price,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveVariant(array $line): ProductVariant
    {
        $variantId = $line['product_variant_id'] ?? null;
        $productId = $line['product_id'] ?? null;

        if ($variantId !== null && $variantId !== '') {
            $variant = ProductVariant::query()->with('product')->find((int) $variantId);

            if (! $variant instanceof ProductVariant) {
                throw BusinessRuleViolation::because('One of the items in your cart is no longer available.');
            }

            if ($productId !== null && $productId !== '' && (int) $variant->product_id !== (int) $productId) {
                throw BusinessRuleViolation::because('The selected item does not match its product.');
            }

            return $variant;
        }

        $product = Product::query()->with('variants')->find((int) $productId);

        if (! $product instanceof Product) {
            throw BusinessRuleViolation::because('One of the items in your cart is no longer available.');
        }

        $variant = $product->defaultVariant();

        if (! $variant instanceof ProductVariant) {
            throw BusinessRuleViolation::because('One of the items in your cart has no purchasable option.');
        }

        return $variant->setRelation('product', $product);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function shippingAddress(array $data, string $customerName): array
    {
        $address = $data['shipping_address'];

        return [
            'contact_name' => $address['contact_name'] ?? $customerName,
            'line_one' => (string) $address['line_one'],
            'line_two' => $address['line_two'] ?? null,
            'city' => (string) $address['city'],
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country' => strtoupper((string) $address['country']),
            'area' => isset($data['area']) ? (string) $data['area'] : null,
        ];
    }

    /**
     * Reuse an existing customer inside this tenant rather than creating a new
     * row on every guest checkout. A store-specific record is preferred over a
     * tenant-wide one, because the address book and order history then stay
     * scoped to the shop the customer actually used.
     *
     * @param  array{name: string, email: string|null, phone: string|null, locale: string}  $attributes
     */
    private function resolveCustomer(Store $store, array $attributes): Customer
    {
        $email = $this->normaliseEmail($attributes['email'] ?? null);
        $phone = $this->normalisePhone($attributes['phone'] ?? null);

        $customer = null;

        foreach ([$email, $phone] as $identity) {
            if ($identity === null || $customer instanceof Customer) {
                continue;
            }

            $customer = $this->findCustomer($store, $identity === $email ? 'email' : 'phone', $identity);
        }

        if (! $customer instanceof Customer) {
            $customer = Customer::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->getKey(),
                'name' => $attributes['name'],
                'email' => $email,
                'phone' => $phone,
                'status' => 'active',
                'locale' => $attributes['locale'] ?: 'en',
            ]);
        } else {
            $updates = [];

            if ($customer->name === '' || $customer->name === null) {
                $updates['name'] = $attributes['name'];
            }

            if ($customer->email === null && $email !== null) {
                $updates['email'] = $email;
            }

            if ($customer->phone === null && $phone !== null) {
                $updates['phone'] = $phone;
            }

            if ($updates !== []) {
                $customer->fill($updates)->save();
            }
        }

        return $customer;
    }

    private function findCustomer(Store $store, string $column, string $value): ?Customer
    {
        $base = Customer::query()->where($column, $value);

        $specific = (clone $base)->where('store_id', $store->getKey())->first();

        if ($specific instanceof Customer) {
            return $specific;
        }

        return (clone $base)->whereNull('store_id')->first();
    }

    /**
     * Store the delivery address in the customer's address book, but only once.
     *
     * @param  array<string, string|null>  $address
     */
    private function rememberAddress(Customer $customer, array $address): void
    {
        $exists = CustomerAddress::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', 'shipping')
            ->where('line_one', $address['line_one'])
            ->where('city', $address['city'])
            ->where('country', $address['country'])
            ->exists();

        if ($exists) {
            return;
        }

        CustomerAddress::query()->create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
            'type' => 'shipping',
            'contact_name' => $address['contact_name'] ?: $customer->name,
            'line_one' => $address['line_one'],
            'line_two' => $address['line_two'],
            'city' => $address['city'],
            'state' => $address['state'],
            'postal_code' => $address['postal_code'],
            'country' => $address['country'],
            'is_default' => ! $customer->addresses()->exists(),
        ]);
    }

    private function normaliseEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    private function normalisePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }

    private function trackingToken(): string
    {
        do {
            $token = Str::random(64);
        } while (Order::query()->withoutGlobalScopes()->where('tracking_token', $token)->exists());

        return $token;
    }
}
