<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The stock ledger, built from the order history {@see OrderSeeder} wrote.
 *
 * Demand is read back out of the database rather than replayed from memory:
 * units held by open orders are reserved, units taken by shipped orders are
 * gone, and a buffer of shelf stock sits behind both. The opening purchase is
 * dated before the oldest order, so the ledger reads like a shop that was
 * already trading when the demo starts.
 *
 * Movement quantities follow {@see InventoryService::applyMovement()}: sales
 * and returns store a signed delta, an adjustment states the new balance, and
 * every row carries the balance it produced.
 */
class InventorySeeder extends Seeder
{
    /**
     * Units stocked beyond demand, from a near-stockout fast mover to a
     * pile nobody has touched.
     *
     * @var list<int>
     */
    private const BUFFERS = [0, 0, 2, 8, 25, 60, 150];

    /** Rows that pick up a low-stock threshold, and the values it takes. */
    private const THRESHOLDS = [5, 10, 20];

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (DemoPeople::tenants() as $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $context->run($tenant, function () use ($tenant): void {
                $this->seedInventory($tenant);
            });
        }
    }

    private function seedInventory(Tenant $tenant): void
    {
        $stores = $tenant->stores()->get();
        $products = Product::query()->with('variants')->get();
        $members = $tenant->users()->get();

        if ($members->isEmpty()) {
            return;
        }

        $owner = $members->first(fn (User $user): bool => (bool) $user->pivot->is_owner) ?? $members->first();
        $demand = $this->demand();

        foreach ($stores as $store) {
            foreach ($products as $product) {
                foreach ($product->variants as $variant) {
                    $this->stock(
                        $tenant,
                        $store,
                        $product,
                        $variant,
                        $demand[$store->getKey().'|'.$variant->getKey()] ?? null,
                        $members,
                        $owner,
                    );
                }
            }
        }
    }

    /**
     * Per (store, variant) demand: what open orders hold and what shipped
     * orders took, with the shipment timestamps that date the ledger's
     * withdrawals.
     *
     * @return array<string, array{reserved: int, consumed: int, shipments: list<array{quantity: int, at: Carbon, order: Order}>}>
     */
    private function demand(): array
    {
        $orders = Order::query()->with(['items', 'statusHistory'])->get();
        $demand = [];

        foreach ($orders as $order) {
            $shippedAt = $order->statusHistory->firstWhere('to_status', OrderStatus::Shipped)?->created_at
                ?? $order->placed_at
                ?? now();

            foreach ($order->items as $item) {
                $key = $order->store_id.'|'.$item->product_variant_id;
                $entry = $demand[$key] ?? ['reserved' => 0, 'consumed' => 0, 'shipments' => []];

                if (in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                    $entry['reserved'] += $item->quantity;
                } elseif (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                    $entry['consumed'] += $item->quantity;
                    $entry['shipments'][] = ['quantity' => $item->quantity, 'at' => $shippedAt, 'order' => $order];
                }

                $demand[$key] = $entry;
            }
        }

        return $demand;
    }

    /**
     * @param  array{reserved: int, consumed: int, shipments: list<array{quantity: int, at: Carbon, order: Order}>}|null  $demand
     * @param  Collection<int, User>  $members
     */
    private function stock(
        Tenant $tenant,
        Store $store,
        Product $product,
        ProductVariant $variant,
        ?array $demand,
        Collection $members,
        User $owner,
    ): void {
        $reserved = $demand['reserved'] ?? 0;
        $consumed = $demand['consumed'] ?? 0;
        $buffer = self::BUFFERS[mt_rand(0, count(self::BUFFERS) - 1)];
        $opening = $consumed + $reserved + $buffer;
        $openedAt = now()->subDays(mt_rand(90, 120));

        $inventory = Inventory::forceCreate([
            'tenant_id' => $tenant->getKey(),
            'store_id' => $store->getKey(),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => $opening,
            'reserved_quantity' => $reserved,
            'low_stock_threshold' => mt_rand(0, 99) < 35
                ? self::THRESHOLDS[mt_rand(0, count(self::THRESHOLDS) - 1)]
                : null,
            'created_at' => $openedAt,
            'updated_at' => now(),
        ]);

        $balance = $opening;

        if ($opening > 0) {
            $this->movement(
                $inventory,
                InventoryMovementType::Purchase,
                $opening,
                $opening,
                $openedAt,
                'Opening stock delivery',
                $owner,
            );
        }

        $shipments = $demand['shipments'] ?? [];
        usort($shipments, fn (array $left, array $right): int => $left['at'] <=> $right['at']);

        $lastEventAt = $openedAt;

        foreach ($shipments as $shipment) {
            $balance -= $shipment['quantity'];
            $lastEventAt = $shipment['at'];

            $this->movement(
                $inventory,
                InventoryMovementType::Sale,
                -$shipment['quantity'],
                $balance,
                $shipment['at'],
                'Order '.$shipment['order']->order_number,
                $members[mt_rand(0, $members->count() - 1)],
                $shipment['order'],
            );
        }

        $balance = $this->noise($inventory, $balance, $reserved, $lastEventAt, $members, $owner);

        $inventory->forceFill(['quantity' => $balance])->save();
    }

    /**
     * The small corrections every real ledger collects: a customer return, a
     * damaged pack written off, a stock take that disagreed. Only a third of
     * rows see one, and never enough to fall below what open orders hold.
     *
     * @param  Collection<int, User>  $members
     */
    private function noise(
        Inventory $inventory,
        int $balance,
        int $reserved,
        Carbon $lastEventAt,
        Collection $members,
        User $owner,
    ): int {
        $roll = mt_rand(0, 99);

        $movement = match (true) {
            $roll < 25 => InventoryMovementType::ReturnIn,
            $roll < 40 => InventoryMovementType::ReturnOut,
            $roll < 55 => InventoryMovementType::Adjustment,
            default => null,
        };

        if ($movement === null) {
            return $balance;
        }

        $at = $lastEventAt->copy()->addDay()->min(now()->subMinutes(2));

        // The shipment arrived moments ago; a correction dated before it would
        // make the balance column read out of order in the ledger.
        if ($at->lte($lastEventAt)) {
            return $balance;
        }

        if ($movement === InventoryMovementType::ReturnIn) {
            $quantity = mt_rand(1, 3);
            $balance += $quantity;

            $this->movement($inventory, $movement, $quantity, $balance, $at, 'Customer return', $owner);
        } elseif ($movement === InventoryMovementType::ReturnOut) {
            $quantity = min(mt_rand(1, 3), $balance - $reserved);

            if ($quantity < 1) {
                return $balance;
            }

            $balance -= $quantity;

            $this->movement($inventory, $movement, -$quantity, $balance, $at, 'Damaged stock write-off', $owner);
        } else {
            $newBalance = $balance + mt_rand(-8, 8);

            if ($newBalance < $reserved || $newBalance === $balance) {
                return $balance;
            }

            // An adjustment states the new truth: the quantity column carries
            // the delta, exactly as applyMovement() would write it.
            $delta = $newBalance - $balance;
            $balance = $newBalance;

            $this->movement($inventory, $movement, $delta, $balance, $at, 'Stock take correction', $members[mt_rand(0, $members->count() - 1)]);
        }

        return $balance;
    }

    /**
     * Append one ledger row. `reference_type` is the morph class, so a
     * movement links back to its order the way the service writes it.
     */
    private function movement(
        Inventory $inventory,
        InventoryMovementType $type,
        int $quantity,
        int $balanceAfter,
        Carbon $at,
        string $note,
        User $user,
        ?Order $reference = null,
    ): void {
        InventoryMovement::forceCreate([
            'tenant_id' => $inventory->tenant_id,
            'inventory_id' => $inventory->getKey(),
            'user_id' => $user->getKey(),
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $balanceAfter,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'created_at' => $at,
        ]);
    }
}
