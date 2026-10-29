<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\NotificationSeverity;
use App\Events\Automation\InventoryLowStock as AutomationInventoryLowStock;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
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
     * `$idempotencyKey` makes a retried request safe: the ledger row is the
     * receipt, so a second call with the same key returns the original movement
     * without touching stock again.
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
        ?string $source = null,
        ?string $idempotencyKey = null,
    ): InventoryMovement {
        if ($quantity === 0 && $type !== InventoryMovementType::Adjustment) {
            throw BusinessRuleViolation::because('A movement quantity must not be zero.');
        }

        $created = false;

        try {
            $movement = DB::transaction(function () use ($inventory, $type, $quantity, $note, $referenceType, $referenceId, $userId, $enforceAvailability, $source, $idempotencyKey, &$created): InventoryMovement {
                // Lock the row so two concurrent orders cannot both read the same
                // "available" figure and oversell the variant.
                $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

                if ($idempotencyKey !== null) {
                    $existing = $this->movementForIdempotencyKey((int) $locked->tenant_id, $idempotencyKey);

                    if ($existing instanceof InventoryMovement) {
                        return $existing;
                    }
                }

                $delta = $quantity * $type->direction();

                if ($type === InventoryMovementType::Adjustment) {
                    // An adjustment states the new truth: the delta is implied.
                    $delta = $quantity - $locked->quantity;
                }

                $newQuantity = $locked->quantity + $delta;
                $allowNegative = $this->allowsNegativeStock();

                if (! $allowNegative && $newQuantity < 0) {
                    throw BusinessRuleViolation::because('Insufficient stock available.');
                }

                if (! $allowNegative && $enforceAvailability && $delta < 0 && $newQuantity - $locked->reserved_quantity < 0) {
                    throw BusinessRuleViolation::because('Not enough unreserved stock to fulfil this movement.');
                }

                if (! $allowNegative && $newQuantity < $locked->reserved_quantity) {
                    throw BusinessRuleViolation::because('Stock cannot drop below the quantity reserved for open orders.');
                }

                $locked->forceFill(['quantity' => $newQuantity])->save();

                $created = true;

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
                    'source' => $source ?? $type->value,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || ! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $this->movementForIdempotencyKey((int) $inventory->tenant_id, $idempotencyKey);

            if (! $existing instanceof InventoryMovement) {
                throw $exception;
            }

            $movement = $existing;
        }

        // Evaluated once the movement committed, and only when nothing wrapped
        // this call in a wider transaction — inside an order transition the
        // caller re-checks after its own commit, so a single shipment does not
        // raise the same alert from every movement it performs.
        if ($created && DB::transactionLevel() === 0) {
            $this->evaluateLowStock($inventory, $movement);
        }

        return $movement;
    }

    /**
     * Set the on-hand quantity to an absolute value (stock take).
     */
    public function adjustToQuantity(Inventory $inventory, int $newQuantity, ?string $note = null, ?string $idempotencyKey = null): InventoryMovement
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
            source: 'manual',
            idempotencyKey: $idempotencyKey,
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
        ?string $source = null,
        ?string $idempotencyKey = null,
    ): InventoryMovement {
        $created = false;

        try {
            $movement = DB::transaction(function () use ($inventory, $quantity, $note, $referenceType, $referenceId, $userId, $source, $idempotencyKey, &$created): InventoryMovement {
                $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

                if ($idempotencyKey !== null) {
                    $existing = $this->movementForIdempotencyKey((int) $locked->tenant_id, $idempotencyKey);

                    if ($existing instanceof InventoryMovement) {
                        return $existing;
                    }
                }

                if (! $this->allowsNegativeStock() && $locked->quantity < $quantity) {
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

                $created = true;

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
                    'source' => $source ?? 'sale',
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || ! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $this->movementForIdempotencyKey((int) $inventory->tenant_id, $idempotencyKey);

            if (! $existing instanceof InventoryMovement) {
                throw $exception;
            }

            $movement = $existing;
        }

        if ($created && DB::transactionLevel() === 0) {
            $this->evaluateLowStock($inventory, $movement);
        }

        return $movement;
    }

    /**
     * Move stock between two stores of the current tenant.
     *
     * The out and in entries are one transaction: if the source cannot cover
     * the transfer, neither store changes. The transfer record links the pair
     * so the ledger explains both sides of the decision.
     */
    public function transfer(
        Store $from,
        Store $to,
        ProductVariant $variant,
        int $quantity,
        ?string $note = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): StockTransfer {
        if ($from->getKey() === $to->getKey()) {
            throw BusinessRuleViolation::because('A stock transfer needs two different stores.');
        }

        if ($quantity < 1) {
            throw BusinessRuleViolation::because('A stock transfer must move at least one unit.');
        }

        $tenantId = (int) $this->tenants->required()->getKey();

        if ((int) $from->tenant_id !== $tenantId || (int) $to->tenant_id !== $tenantId) {
            throw BusinessRuleViolation::because('Both stores must belong to the active tenant.');
        }

        $fromInventory = null;
        $toInventory = null;
        $created = false;

        try {
            $transfer = DB::transaction(function () use ($from, $to, $variant, $quantity, $note, $userId, $idempotencyKey, $tenantId, &$fromInventory, &$toInventory, &$created): StockTransfer {
                if ($idempotencyKey !== null) {
                    $existing = StockTransfer::query()
                        ->where('tenant_id', $tenantId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing instanceof StockTransfer) {
                        return $existing->load(['fromStore', 'toStore', 'product', 'variant', 'user']);
                    }
                }

                $transfer = StockTransfer::query()->create([
                    'tenant_id' => $tenantId,
                    'from_store_id' => $from->getKey(),
                    'to_store_id' => $to->getKey(),
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => $quantity,
                    'note' => $note,
                    'user_id' => $userId ?? auth()->id(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $fromInventory = $this->findOrCreate($from, $variant);
                $toInventory = $this->findOrCreate($to, $variant);

                $out = $this->applyMovement(
                    $fromInventory,
                    InventoryMovementType::TransferOut,
                    $quantity,
                    $note ?? 'Transfer to '.$to->name,
                    $transfer->getMorphClass(),
                    $transfer->getKey(),
                    $userId,
                    source: 'transfer',
                    idempotencyKey: $idempotencyKey !== null ? $idempotencyKey.':out' : null,
                );

                $in = $this->applyMovement(
                    $toInventory,
                    InventoryMovementType::TransferIn,
                    $quantity,
                    $note ?? 'Transfer from '.$from->name,
                    $transfer->getMorphClass(),
                    $transfer->getKey(),
                    $userId,
                    source: 'transfer',
                    idempotencyKey: $idempotencyKey !== null ? $idempotencyKey.':in' : null,
                );

                $transfer->forceFill([
                    'from_movement_id' => $out->getKey(),
                    'to_movement_id' => $in->getKey(),
                ])->save();

                $created = true;

                return $transfer->load(['fromStore', 'toStore', 'product', 'variant', 'user']);
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || ! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = StockTransfer::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing instanceof StockTransfer) {
                throw $exception;
            }

            $transfer = $existing->load(['fromStore', 'toStore', 'product', 'variant', 'user']);
        }

        if ($created && DB::transactionLevel() === 0) {
            if ($fromInventory instanceof Inventory) {
                $this->evaluateLowStock($fromInventory, $transfer->from_movement_id);
            }

            if ($toInventory instanceof Inventory) {
                $this->evaluateLowStock($toInventory, $transfer->to_movement_id);
            }
        }

        return $transfer;
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
    public function evaluateLowStock(Inventory $inventory, ?int $movementId = null): void
    {
        $fresh = Inventory::query()->with(['product', 'variant', 'store'])->find($inventory->getKey());

        if (! $fresh instanceof Inventory || $fresh->low_stock_threshold === null) {
            return;
        }

        if ($fresh->available() > $fresh->low_stock_threshold) {
            return;
        }

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_INVENTORY_LOW_STOCK,
            tenantId: (int) $fresh->tenant_id,
            dedupeKey: 'tenant.inventory.low_stock:'.$fresh->getKey(),
            data: [
                'product' => $fresh->product?->name ?? 'A product',
                'quantity' => $fresh->available(),
                'on_hand' => $fresh->quantity,
                'reserved' => $fresh->reserved_quantity,
                'threshold' => $fresh->low_stock_threshold,
                'store' => $fresh->store?->name ?? '',
            ],
            actionUrl: '/inventory',
            actorId: auth()->id(),
            severity: $fresh->available() <= 0 ? NotificationSeverity::Critical : NotificationSeverity::Warning,
        ));

        event(AutomationInventoryLowStock::fromInventory($fresh, $movementId));
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
                $movementId = $inventory->movements()->latest('id')->value('id');
                $this->evaluateLowStock($inventory, $movementId !== null ? (int) $movementId : null);
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
            ->orderByRaw('(quantity - reserved_quantity) asc')
            ->limit($limit)
            ->get();
    }

    private function movementForIdempotencyKey(int $tenantId, string $idempotencyKey): ?InventoryMovement
    {
        return InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || in_array($driverCode, [19, 1062], true);
    }

    private function allowsNegativeStock(): bool
    {
        return (bool) config('inventory.allow_negative_stock', false);
    }
}
