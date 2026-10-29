<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Executors;

use App\Enums\AiActionClassification;
use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use App\Services\Inventory\InventoryService;
use App\Support\Tenancy\TenantContext;

/**
 * Sets the stock level of a variant in a store.
 *
 * The proposal may only name a store and a variant that belong to this
 * tenant, and the quantity must be a real, non-negative number. Everything the
 * model proposed about those records is replaced with what the database holds
 * before anything is written.
 *
 * The change goes through {@see InventoryService}, so it lands in the stock
 * ledger like any manual adjustment and respects the same rules — a quantity
 * below the reserved amount is refused.
 */
final class InventoryStockAdjustmentExecutor implements AiActionExecutor
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly TenantContext $tenants,
    ) {}

    public function actionType(): string
    {
        return 'inventory.adjust';
    }

    public function classification(): AiActionClassification
    {
        return AiActionClassification::ReversibleChange;
    }

    public function requiredPermission(): string
    {
        return 'inventory.manage';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $storeId = (int) ($payload['store_id'] ?? 0);
        $variantId = (int) ($payload['product_variant_id'] ?? 0);
        $quantity = $payload['quantity'] ?? null;
        $note = $payload['note'] ?? null;

        if ($storeId <= 0 || $variantId <= 0) {
            throw BusinessRuleViolation::because('The proposal must name a store and a product variant.');
        }

        if (! is_numeric($quantity) || (int) $quantity < 0) {
            throw BusinessRuleViolation::because('The proposed quantity is not a valid stock level.');
        }

        // The tenant scope applies to both queries, so a store or a variant
        // belonging to another merchant simply is not found here.
        $store = Store::query()->find($storeId);
        $variant = ProductVariant::query()->with('product')->find($variantId);

        if (! $store instanceof Store || ! $variant instanceof ProductVariant) {
            throw BusinessRuleViolation::because('The proposed store or product variant does not exist.');
        }

        if ($store->status !== StoreStatus::Active) {
            throw BusinessRuleViolation::because('The proposed store is not active.');
        }

        if ($variant->product?->status === ProductStatus::Archived) {
            throw BusinessRuleViolation::because('The proposed product is archived.');
        }

        $inventory = Inventory::query()
            ->where('store_id', $store->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->first();

        if (! $inventory instanceof Inventory) {
            throw BusinessRuleViolation::because('That product variant is not stocked in the proposed store.');
        }

        return [
            'inventory_id' => $inventory->getKey(),
            'store_id' => $store->getKey(),
            'store_name' => $store->name,
            'product_variant_id' => $variant->getKey(),
            'product_name' => $variant->product?->name ?? $variant->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku ?? $variant->product?->sku,
            'quantity' => (int) $quantity,
            'current_quantity' => (int) $inventory->quantity,
            'expected_quantity' => (int) $inventory->quantity,
            'expected_reserved_quantity' => (int) $inventory->reserved_quantity,
            'note' => is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : 'AI-assisted stock adjustment',
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
            'product_variant_id' => $payload['product_variant_id'] ?? null,
            'quantity' => $payload['quantity'] ?? null,
            'note' => $payload['note'] ?? null,
        ]);

        if (
            (int) $fresh['expected_quantity'] !== (int) ($payload['expected_quantity'] ?? -1)
            || (int) $fresh['expected_reserved_quantity'] !== (int) ($payload['expected_reserved_quantity'] ?? -1)
        ) {
            throw BusinessRuleViolation::because(
                'Stock changed after this action was proposed. Review the new balance and approve it again.',
            );
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, User $actor, string $idempotencyKey): array
    {
        $inventory = Inventory::query()->findOrFail((int) $payload['inventory_id']);

        $movement = $this->inventory->adjustToQuantity(
            $inventory,
            (int) $payload['quantity'],
            (string) $payload['note'],
        );

        return [
            'inventory_id' => $inventory->getKey(),
            'movement_id' => $movement->getKey(),
            'quantity' => (int) $payload['quantity'],
            'balance_after' => $movement->balance_after,
        ];
    }

    /**
     * The tenant the action belongs to, used to keep the executor honest.
     */
    public function tenantId(): ?int
    {
        return $this->tenants->id();
    }
}
