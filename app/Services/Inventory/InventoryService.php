<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\NotificationSeverity;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stock ledger.
 *
 * Every mutation goes through `applyMovement()` so the on-hand quantity and its
 * ledger entry can never disagree. Movements are appended only; corrections are
 * expressed as an opposing movement rather than by editing history.
 */
class InventoryService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Get (or create) the inventory row for a variant in a store.
     *
     * Rows are created lazily on first movement rather than in bulk at product
     * creation, because a tenant with thousands of products and a handful of
     * stores should not pay for rows nobody has stocked.
     */
    public function findOrCreate(Store $store, ProductVariant $variant, ?int $lowStockThreshold = null): Inventory
    {
        return Inventory::query()->firstOrCreate(
            [
                'store_id' => $store->getKey(),
                'product_variant_id' => $variant->getKey(),
            ],
            [
                'tenant_id' => $this->tenants->required()->getKey(),
                'product_id' => $variant->product_id,
                'quantity' => 0,
                'reserved_quantity' => 0,
                'low_stock_threshold' => $lowStockThreshold,
            ],
        );
    }

    /**
     * Post a movement and update the balance atomically.
     *
     * @param  class-string<Model>|null  $referenceType
     */
    public function applyMovement(
        Inventory $inventory,
        InventoryMovementType $type,
        int $quantity,
        ?string $note = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $userId = null,
        bool $enforceAvailability = true,
    ): InventoryMovement {
        if ($quantity === 0 && $type !== InventoryMovementType::Adjustment) {
            throw BusinessRuleViolation::because('A movement quantity must not be zero.');
        }

        $movement = DB::transaction(function () use ($inventory, $type, $quantity, $note, $referenceType, $referenceId, $userId, $enforceAvailability): InventoryMovement {
            // Lock the row so two concurrent orders cannot both read the same
            // "available" figure and oversell the variant.
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            $delta = $quantity * $type->direction();

            if ($type === InventoryMovementType::Adjustment) {
                // An adjustment states the new truth: the delta is implied.
                $delta = $quantity - $locked->quantity;
            }

            $newQuantity = $locked->quantity + $delta;

            if ($newQuantity < 0) {
                throw BusinessRuleViolation::because('Insufficient stock available.');
            }

            if ($enforceAvailability && $delta < 0 && $newQuantity - $locked->reserved_quantity < 0) {
                throw BusinessRuleViolation::because('Not enough unreserved stock to fulfil this movement.');
            }

            if ($newQuantity < $locked->reserved_quantity) {
                throw BusinessRuleViolation::because('Stock cannot drop below the quantity reserved for open orders.');
            }

            $locked->forceFill(['quantity' => $newQuantity])->save();

            return InventoryMovement::query()->create([
                'tenant_id' => $locked->tenant_id,
                'inventory_id' => $locked->getKey(),
                'user_id' => $userId ?? auth()->id(),
                'type' => $type,
                'quantity' => $delta,
                'balance_after' => $newQuantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'created_at' => now(),
            ]);
        });

        // Evaluated once the movement committed, and only when nothing wrapped
        // this call in a wider transaction — inside an order transition the
        // caller re-checks after its own commit, so a single shipment does not
        // raise the same alert from every movement it performs.
        if (DB::transactionLevel() === 0) {
            $this->evaluateLowStock($inventory);
        }

        return $movement;
    }

    /**
     * Set the on-hand quantity to an absolute value (stock take).
     */
    public function adjustToQuantity(Inventory $inventory, int $newQuantity, ?string $note = null): InventoryMovement
    {
        if ($newQuantity < 0) {
            throw BusinessRuleViolation::because('Stock cannot be negative.');
        }

        if ($newQuantity < $inventory->reserved_quantity) {
            throw BusinessRuleViolation::because('Stock cannot be set below the quantity reserved for open orders.');
        }

        return $this->applyMovement(
            $inventory,
            InventoryMovementType::Adjustment,
            $newQuantity,
            $note ?? 'Manual stock adjustment',
            enforceAvailability: false,
        );
    }

    /**
     * Hold stock for an order line.
     *
     * The read and the write happen under a row lock in one transaction, so two
     * orders placed in the same moment cannot both pass the availability check and
     * together reserve more than exists.
     */
    public function reserve(Inventory $inventory, int $quantity, ?string $referenceType = null, ?int $referenceId = null): void
    {
        DB::transaction(function () use ($inventory, $quantity): void {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if ($locked->available() < $quantity) {
                throw BusinessRuleViolation::because(sprintf(
                    'Insufficient stock (available: %d, requested: %d).',
                    $locked->available(),
                    $quantity,
                ));
            }

            $locked->forceFill([
                'reserved_quantity' => $locked->reserved_quantity + $quantity,
            ])->save();
        });
    }

    /**
     * Turn a reservation into a real withdrawal when an order ships.
     *
     * Both columns move in the same locked statement. Writing them separately is
     * what previously let a shipment be refused for "dropping below reserved"
     * against the very reservation it was fulfilling.
     */
    public function consume(
        Inventory $inventory,
        int $quantity,
        ?string $note = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $userId = null,
    ): InventoryMovement {
        $movement = DB::transaction(function () use ($inventory, $quantity, $note, $referenceType, $referenceId, $userId): InventoryMovement {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if ($locked->quantity < $quantity) {
                throw BusinessRuleViolation::because(sprintf(
                    'Only %d units remain in stock; %d were requested.',
                    $locked->quantity,
                    $quantity,
                ));
            }

            $newQuantity = $locked->quantity - $quantity;

            $locked->forceFill([
                'quantity' => $newQuantity,
                // Shipment settles the reservation made for it; a surplus
                // reservation belongs to other orders and must survive.
                'reserved_quantity' => max(0, $locked->reserved_quantity - $quantity),
            ])->save();

            return InventoryMovement::query()->create([
                'tenant_id' => $locked->tenant_id,
                'inventory_id' => $locked->getKey(),
                'user_id' => $userId ?? auth()->id(),
                'type' => InventoryMovementType::Sale,
                'quantity' => -$quantity,
                'balance_after' => $newQuantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'created_at' => now(),
            ]);
        });

        if (DB::transactionLevel() === 0) {
            $this->evaluateLowStock($inventory);
        }

        return $movement;
    }

    /**
     * Return held stock to availability (an order was cancelled or its lines
     * replaced). Reservations are only ever lowered, never below zero.
     *
     * @param  array<int, array{product_variant_id: int, store_id: int, quantity: int}>  $lines
     */
    public function release(Store $store, array $lines, ?string $referenceType = null, ?int $referenceId = null): void
    {
        if ($lines === []) {
            return;
        }

        DB::transaction(function () use ($store, $lines): void {
            foreach ($lines as $line) {
                $inventory = Inventory::query()
                    ->where('store_id', $store->getKey())
                    ->where('product_variant_id', $line['product_variant_id'])
                    ->lockForUpdate()
                    ->first();

                if ($inventory === null) {
                    continue;
                }

                $inventory->forceFill([
                    'reserved_quantity' => max(0, $inventory->reserved_quantity - $line['quantity']),
                ])->save();
            }
        });
    }

    /**
     * Raise a low-stock alert if this row has fallen to or below its threshold.
     *
     * The dispatcher's per-recipient cooldown is what stops a busy day becoming
     * one email per order; this method only decides *whether* the row is low,
     * never how often the merchant is told.
     */
    public function evaluateLowStock(Inventory $inventory): void
    {
        $fresh = Inventory::query()->with(['product', 'store'])->find($inventory->getKey());

        if (! $fresh instanceof Inventory || $fresh->low_stock_threshold === null) {
            return;
        }

        if ($fresh->quantity > $fresh->low_stock_threshold) {
            return;
        }

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_INVENTORY_LOW_STOCK,
            tenantId: (int) $fresh->tenant_id,
            dedupeKey: 'tenant.inventory.low_stock:'.$fresh->getKey(),
            data: [
                'product' => $fresh->product?->name ?? 'A product',
                'quantity' => $fresh->quantity,
                'threshold' => $fresh->low_stock_threshold,
                'store' => $fresh->store?->name ?? '',
            ],
            actionUrl: '/inventory',
            actorId: auth()->id(),
            severity: $fresh->quantity <= 0 ? NotificationSeverity::Critical : NotificationSeverity::Warning,
        ));
    }

    /**
     * Re-check every inventory row an order touched. Called by the order
     * service after its transaction committed, because inside that transaction
     * the individual movements deliberately defer their own check.
     */
    public function evaluateLowStockForOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = Inventory::query()
                ->where('store_id', $order->store_id)
                ->where('product_variant_id', $item->product_variant_id)
                ->first();

            if ($inventory instanceof Inventory) {
                $this->evaluateLowStock($inventory);
            }
        }
    }

    /**
     * Rows that have fallen to (or below) their low-stock threshold.
     *
     * @return Collection<int, Inventory>
     */
    public function lowStock(?Store $store = null, int $limit = 10): Collection
    {
        return Inventory::query()
            ->with(['product', 'variant', 'store'])
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->lowStock()
            ->orderBy('quantity')
            ->limit($limit)
            ->get();
    }
}
