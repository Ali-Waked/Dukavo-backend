<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\Inventory;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InventoryOutOfStock implements AutomationEvent
{
    use Dispatchable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        private int $tenantId,
        private ?int $storeId,
        private array $context,
        private string $eventKey,
    ) {}

    public static function fromInventory(Inventory $inventory, ?int $movementId = null, ?int $previousAvailable = null): self
    {
        $inventory->loadMissing(['product', 'variant', 'store']);

        $source = $movementId !== null
            ? 'movement:'.$movementId
            : 'observed:'.$inventory->updated_at?->format('U.u');

        return new self(
            tenantId: (int) $inventory->tenant_id,
            storeId: $inventory->store_id !== null ? (int) $inventory->store_id : null,
            context: self::buildContext($inventory, $previousAvailable),
            eventKey: 'inventory.out_of_stock:'.$inventory->getKey().':'.$source,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function buildContext(Inventory $inventory, ?int $previousAvailable = null): array
    {
        return [
            'inventory_id' => (int) $inventory->getKey(),
            'store_id' => $inventory->store_id !== null ? (int) $inventory->store_id : null,
            'store_name' => (string) ($inventory->store?->name ?? ''),
            'product_id' => (int) $inventory->product_id,
            'product_variant_id' => $inventory->product_variant_id !== null ? (int) $inventory->product_variant_id : null,
            'product_name' => (string) ($inventory->product?->name ?? 'A product'),
            'sku' => (string) ($inventory->variant?->sku ?? ''),
            'quantity' => (int) $inventory->quantity,
            'reserved_quantity' => (int) $inventory->reserved_quantity,
            'available' => $inventory->available(),
            'previous_available' => $previousAvailable,
            'threshold' => $inventory->low_stock_threshold !== null ? (int) $inventory->low_stock_threshold : null,
        ];
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function storeId(): ?int
    {
        return $this->storeId;
    }

    public function trigger(): AutomationTrigger
    {
        return AutomationTrigger::InventoryOutOfStock;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function dedupeKey(): string
    {
        return $this->eventKey;
    }
}
