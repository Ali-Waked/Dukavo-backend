<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoCatalog;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The order history a demo merchant lives by.
 *
 * Every total is computed with the exact arithmetic of
 * {@see OrderService::recalculate()} — per-line rounding, tax per line,
 * shipping waived above $75 — so the numbers reconcile the way the API would
 * make them. The rows themselves are written directly rather than through the
 * service because an order placed six weeks ago must carry six-week-old
 * timestamps, which the service refuses to create.
 *
 * {@see InventorySeeder} runs *after* this seeder and reads the shipment
 * timestamps written here to build the stock ledger.
 */
class OrderSeeder extends Seeder
{
    /** The tax rate checkout applies to taxable lines. */
    private const TAX_RATE = 0.16;

    private const SHIPPING_FLAT = 4.99;

    private const FREE_SHIPPING_FROM = 75.0;

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (DemoPeople::tenants() as $offset => $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $context->run($tenant, function () use ($tenant, $definition, $offset): void {
                $this->seedOrders($tenant, $definition, $offset);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedOrders(Tenant $tenant, array $definition, int $offset): void
    {
        $stores = $tenant->stores()->orderBy('id')->get();
        $customers = Customer::query()->with('addresses')->get()->values();
        $members = $tenant->users()->get();

        $products = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->with('variants')
            ->get();

        $pool = $this->variantPool($products);

        if ($pool === [] || $members->isEmpty()) {
            $this->command?->warn(sprintf('No order material for [%s]; skipped.', $definition['name']));

            return;
        }

        $owner = $members->first(fn (User $user): bool => (bool) $user->pivot->is_owner) ?? $members->first();
        $sequence = 1;
        $lastOrderAt = [];
        $todayOrders = [];
        $paidToday = false;

        foreach ($this->ages((int) $definition['order_count']) as $age) {
            $placedAt = $this->placedAt($age);
            $status = $this->statusFor($age);
            $payment = $this->paymentFor($status, $age);
            $store = $stores[mt_rand(0, $stores->count() - 1)];

            $customer = $customers->isNotEmpty() && mt_rand(0, 99) < 85
                ? $customers[mt_rand(0, $customers->count() - 1)]
                : null;

            $built = $this->buildLines($pool);
            $shipping = ($built['subtotal'] - $built['discount']) >= self::FREE_SHIPPING_FROM
                ? 0.0
                : self::SHIPPING_FLAT;
            $grandTotal = round($built['subtotal'] - $built['discount'] + $built['tax'] + $shipping, 2);

            if ($customer !== null) {
                $name = $customer->name;
                $phone = $customer->phone;
                $address = $this->addressFor($customer);
            } else {
                // Walk-in and phone orders carry a name and number but no
                // delivery address: the goods never left the counter.
                $guest = DemoPeople::customer((string) $definition['locale'], $offset, mt_rand(0, 34));
                $name = $guest['name'];
                $phone = $guest['phone'];
                $address = null;
            }

            $timeline = $this->timeline($status, $placedAt, $owner, $members);
            $cancelledAt = $this->stepAt($timeline, OrderStatus::Cancelled);
            $finishedAt = $timeline[array_key_last($timeline)]['at'];

            $order = Order::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'store_id' => $store->getKey(),
                'customer_id' => $customer?->getKey(),
                'order_number' => sprintf('%s-%0'.(int) config('dukavo.order_number.padding', 6).'d',
                    strtoupper((string) config('dukavo.order_number.prefix', 'ORD')), $sequence),
                'status' => $status,
                'payment_status' => $payment,
                'currency' => $store->currency,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'shipping_address' => $address,
                'notes' => mt_rand(0, 99) < 18 ? $this->notes((string) $definition['locale']) : null,
                'subtotal' => $built['subtotal'],
                'discount_total' => $built['discount'],
                'tax_total' => $built['tax'],
                'shipping_total' => $shipping,
                'grand_total' => $grandTotal,
                'placed_at' => $placedAt,
                'cancelled_at' => $cancelledAt,
                'created_at' => $placedAt,
                'updated_at' => $finishedAt,
            ]);

            $sequence++;

            foreach ($built['lines'] as $row) {
                OrderItem::forceCreate([
                    'tenant_id' => $tenant->getKey(),
                    'order_id' => $order->getKey(),
                    'product_id' => $row['product']->getKey(),
                    'product_variant_id' => $row['variant']->getKey(),
                    'name' => $row['product']->name.($row['variant']->is_default ? '' : ' — '.$row['variant']->name),
                    'sku' => $row['variant']->sku ?? $row['product']->sku,
                    'unit_price' => $row['unit_price'],
                    'quantity' => $row['quantity'],
                    'discount_total' => $row['discount_total'],
                    'tax_total' => $row['tax_total'],
                    'line_total' => $row['line_total'],
                    'created_at' => $placedAt,
                    'updated_at' => $placedAt,
                ]);
            }

            $from = null;

            foreach ($timeline as $step) {
                OrderStatusHistory::forceCreate([
                    'tenant_id' => $tenant->getKey(),
                    'order_id' => $order->getKey(),
                    'user_id' => $step['user']->getKey(),
                    'from_status' => $from,
                    'to_status' => $step['to'],
                    'note' => $step['note'],
                    'created_at' => $step['at'],
                ]);

                $from = $step['to'];
            }

            if ($customer !== null) {
                $previous = $lastOrderAt[$customer->getKey()] ?? null;

                if ($previous === null || $placedAt->greaterThan($previous)) {
                    $lastOrderAt[$customer->getKey()] = $placedAt;
                }
            }

            if ($age === 0) {
                $todayOrders[] = $order;
                $paidToday = $paidToday || $payment === PaymentStatus::Paid;
            }
        }

        // The dashboard's "revenue today" card must never read $0 on a demo
        // database, so one of today's orders settles if none did by chance.
        if (! $paidToday && $todayOrders !== []) {
            $todayOrders[0]->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        }

        // The payments screen shows money in every state, so each tenant
        // keeps a handful of part-settled orders even when the draw came up
        // short: the oldest unpaid ones take a deposit.
        $partials = Order::query()
            ->where('payment_status', PaymentStatus::PartiallyPaid->value)
            ->count();

        if ($partials < 3) {
            Order::query()
                ->where('payment_status', PaymentStatus::Unpaid->value)
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->orderBy('id')
                ->limit(3 - $partials)
                ->get()
                ->each(fn (Order $order) => $order
                    ->forceFill(['payment_status' => PaymentStatus::PartiallyPaid])
                    ->save());
        }

        foreach ($lastOrderAt as $customerId => $placedAt) {
            Customer::query()->whereKey($customerId)->update([
                'last_order_at' => $placedAt,
                'updated_at' => $placedAt,
            ]);
        }
    }

    /**
     * Weighted (product, variant) pairs: core lines appear more often than
     * niche ones, so the bestseller list has a shape.
     *
     * @param  Collection<int, Product>  $products
     * @return list<array{product: Product, variant: ProductVariant}>
     */
    private function variantPool(Collection $products): array
    {
        $tiers = [];

        foreach (DemoCatalog::products() as $spec) {
            $tiers[$spec['key']] = $spec['tier'];
        }

        $pool = [];

        foreach ($products as $product) {
            $weight = 4 - ($tiers[$product->slug] ?? 2);

            foreach ($product->variants as $variant) {
                array_push($pool, ...array_fill(0, $weight, ['product' => $product, 'variant' => $variant]));
            }
        }

        return $pool;
    }

    /**
     * Order ages in days, oldest first so the sequence numbers climb with
     * time. Recent days are drawn more often than old ones: a shop's history
     * thins out as it recedes.
     *
     * @return list<int>
     */
    private function ages(int $count): array
    {
        $ages = [];

        for ($i = 0; $i < $count; $i++) {
            if ($i < 2) {
                $ages[] = 0;

                continue;
            }

            $draw = mt_rand(0, 10000) / 10000;
            $ages[] = (int) floor(74 * pow($draw, 1.4));
        }

        sort($ages);

        return $ages;
    }

    /**
     * When the order was placed: business hours on a past day, or earlier
     * today for the freshest orders.
     */
    private function placedAt(int $age): Carbon
    {
        if ($age === 0) {
            $at = now()->subMinutes(mt_rand(15, 300));
            $startOfDay = Carbon::today()->addMinute();

            return $at->lt($startOfDay) ? $startOfDay->min(now()) : $at;
        }

        return Carbon::today()->subDays($age)->setTime(mt_rand(8, 21), mt_rand(0, 59));
    }

    /**
     * How far fulfilment got by now. The demo mirrors the store's real
     * behaviour: yesterday's order is still being picked, last week's is on
     * the van or delivered, and nothing is left hanging for a month.
     */
    private function statusFor(int $age): OrderStatus
    {
        $roll = mt_rand(0, 99);

        return match (true) {
            $age <= 1 => match (true) {
                $roll < 35 => OrderStatus::Pending,
                $roll < 60 => OrderStatus::Confirmed,
                $roll < 70 => OrderStatus::Processing,
                $roll < 80 => OrderStatus::Shipped,
                $roll < 95 => OrderStatus::Delivered,
                default => OrderStatus::Cancelled,
            },
            $age <= 6 => match (true) {
                $roll < 15 => OrderStatus::Confirmed,
                $roll < 35 => OrderStatus::Processing,
                $roll < 60 => OrderStatus::Shipped,
                $roll < 95 => OrderStatus::Delivered,
                default => OrderStatus::Cancelled,
            },
            $age <= 20 => match (true) {
                $roll < 5 => OrderStatus::Processing,
                $roll < 10 => OrderStatus::Shipped,
                $roll < 85 => OrderStatus::Delivered,
                default => OrderStatus::Cancelled,
            },
            default => match (true) {
                $roll < 88 => OrderStatus::Delivered,
                default => OrderStatus::Cancelled,
            },
        };
    }

    /**
     * Settlement state. A delivered order is always paid — that is exactly
     * what {@see \App\Services\Orders\OrderService::transition()} does — and
     * recent orders may still be waiting on cash on delivery.
     */
    private function paymentFor(OrderStatus $status, int $age): PaymentStatus
    {
        $roll = mt_rand(0, 99);

        if ($status === OrderStatus::Delivered) {
            return PaymentStatus::Paid;
        }

        if ($status === OrderStatus::Cancelled) {
            return $roll < 65 ? PaymentStatus::Refunded : PaymentStatus::Unpaid;
        }

        if ($age <= 3) {
            return match (true) {
                $roll < 55 => PaymentStatus::Paid,
                $roll < 70 => PaymentStatus::PartiallyPaid,
                default => PaymentStatus::Unpaid,
            };
        }

        return match (true) {
            $roll < 78 => PaymentStatus::Paid,
            $roll < 88 => PaymentStatus::PartiallyPaid,
            default => PaymentStatus::Unpaid,
        };
    }

    /**
     * Pick the lines and price them exactly as the checkout would.
     *
     * @param  list<array{product: Product, variant: ProductVariant}>  $pool
     * @return array{lines: list<array<string, mixed>>, subtotal: float, discount: float, tax: float}
     */
    private function buildLines(array $pool): array
    {
        $roll = mt_rand(0, 99);
        $wanted = match (true) {
            $roll < 25 => 1,
            $roll < 55 => 2,
            $roll < 80 => 3,
            $roll < 92 => 4,
            default => 5,
        };

        $picked = [];

        for ($attempt = 0; $attempt < 60 && count($picked) < $wanted; $attempt++) {
            $candidate = $pool[mt_rand(0, count($pool) - 1)];
            $picked[$candidate['variant']->getKey()] = $candidate;
        }

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];

        foreach ($picked as $row) {
            $product = $row['product'];
            $variant = $row['variant'];
            $quantity = mt_rand(0, 99) < 85 ? mt_rand(1, 3) : mt_rand(4, 6);
            $unitPrice = (float) $variant->price;
            $lineSubtotal = round($unitPrice * $quantity, 2);

            $proposed = mt_rand(0, 99) < 18
                ? round($lineSubtotal * [0.05, 0.10, 0.15][mt_rand(0, 2)], 2)
                : 0.0;

            // From here the arithmetic is OrderService's, line for line.
            $lineDiscount = round(min($proposed, $lineSubtotal), 2);
            $lineTaxable = max(0.0, $lineSubtotal - $lineDiscount);
            $lineTax = $product->is_taxable ? round($lineTaxable * self::TAX_RATE, 2) : 0.0;
            $lineTotal = round($lineTaxable + $lineTax, 2);

            $lines[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_total' => $lineDiscount,
                'tax_total' => $lineTax,
                'line_total' => $lineTotal,
            ];

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
        }

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountTotal, 2),
            'tax' => round($taxTotal, 2),
        ];
    }

    /**
     * The fulfilment trail, expressed as fractions of the time between
     * placing and now — so a step can never be dated after the present or
     * before the step before it.
     *
     * @param  Collection<int, User>  $members
     * @return list<array{to: OrderStatus, fraction: float, note: string, user: User}>
     */
    private function timeline(OrderStatus $status, Carbon $placedAt, User $owner, Collection $members): array
    {
        $steps = [
            ['to' => OrderStatus::Pending, 'fraction' => 0.0, 'note' => 'Order created', 'user' => $owner],
        ];

        if ($status === OrderStatus::Cancelled) {
            $steps[] = [
                'to' => OrderStatus::Cancelled,
                'fraction' => mt_rand(15, 35) / 100,
                'note' => 'Cancelled by the customer',
                'user' => $owner,
            ];

            return $this->dateSteps($steps, $placedAt);
        }

        if ($status === OrderStatus::Pending) {
            return $this->dateSteps($steps, $placedAt);
        }

        $chain = [
            ['to' => OrderStatus::Confirmed, 'fraction' => mt_rand(8, 14) / 100, 'note' => 'Order confirmed'],
            ['to' => OrderStatus::Processing, 'fraction' => mt_rand(25, 33) / 100, 'note' => 'Picking started'],
            ['to' => OrderStatus::Shipped, 'fraction' => mt_rand(55, 65) / 100, 'note' => 'Handed to the courier'],
            ['to' => OrderStatus::Delivered, 'fraction' => mt_rand(88, 96) / 100, 'note' => 'Delivered to the customer'],
        ];

        foreach ($chain as $link) {
            $steps[] = [
                'to' => $link['to'],
                'fraction' => $link['fraction'],
                'note' => $link['note'],
                'user' => $link['to'] === OrderStatus::Delivered
                    ? $owner
                    : $members[mt_rand(0, $members->count() - 1)],
            ];

            if ($link['to'] === $status) {
                break;
            }
        }

        return $this->dateSteps($steps, $placedAt);
    }

    /**
     * Turn the fractions into timestamps.
     *
     * @param  list<array{to: OrderStatus, fraction: float, note: string, user: User}>  $steps
     * @return list<array{to: OrderStatus, fraction: float, note: string, user: User, at: Carbon}>
     */
    private function dateSteps(array $steps, Carbon $placedAt): array
    {
        $elapsed = (float) $placedAt->diffInMilliseconds(now());

        foreach ($steps as $index => $step) {
            $steps[$index]['at'] = $placedAt->copy()->addMilliseconds((int) round($elapsed * $step['fraction']));
        }

        return $steps;
    }

    /**
     * @param  list<array{to: OrderStatus, at: Carbon}>  $timeline
     */
    private function stepAt(array $timeline, OrderStatus $step): ?Carbon
    {
        foreach ($timeline as $entry) {
            if ($entry['to'] === $step) {
                return $entry['at'];
            }
        }

        return null;
    }

    /**
     * The customer's default delivery address in the shape orders snapshot.
     *
     * @return array{contact_name: string, line_one: string, line_two: string|null, city: string, state: string|null, postal_code: string|null, country: string}|null
     */
    private function addressFor(Customer $customer): ?array
    {
        $address = $customer->addresses->firstWhere('is_default', true) ?? $customer->addresses->first();

        if ($address === null) {
            return null;
        }

        return [
            'contact_name' => $address->contact_name,
            'line_one' => $address->line_one,
            'line_two' => $address->line_two,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
        ];
    }

    private function notes(string $locale): string
    {
        $notes = $locale === 'ar'
            ? ['يرجى الجرس مرتين.', 'سلّم للمشرف إذا لا أحد يفتح.', 'بدون أكياس بلاستيك من فضلك.']
            : ['Please ring the bell twice.', 'Leave with the concierge if no answer.', 'No plastic bags, thank you.'];

        return $notes[mt_rand(0, count($notes) - 1)];
    }
}
