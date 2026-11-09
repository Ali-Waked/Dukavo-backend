<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\Inventory;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InventoryRestocked implements AutomationEvent
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

    public static function fromInventory(Inventory $inventory, int $previousAvailable, ?int $movementId = null): self
    {
        $inventory->loadMissing(['product', 'variant', 'store']);

        $source = $movementId !== null
            ? 'movement:'.$movementId
            : 'observed:'.$inventory->updated_at?->format('U.u');

        return new self(
            tenantId: (int) $inventory->tenant_id,
            storeId: $inventory->store_id !== null ? (int) $inventory->store_id : null,
            context: InventoryOutOfStock::buildContext($inventory, $previousAvailable),
            eventKey: 'inventory.restocked:'.$inventory->getKey().':'.$source,
        );
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
        return AutomationTrigger::InventoryRestocked;
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
