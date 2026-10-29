<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only inventory ledger entry.
 *
 * `quantity` is the signed delta applied to the inventory row and
 * `balance_after` records the resulting on-hand quantity, so a stock level can
 * always be explained (and an error spotted) without replaying the ledger.
 */
#[Fillable(['tenant_id', 'inventory_id', 'user_id', 'type', 'quantity', 'balance_after', 'reference_type', 'reference_id', 'note', 'source', 'idempotency_key', 'created_at'])]
class InventoryMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'reference_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A short human label for the originating document, when there is one.
     */
    public function referenceLabel(): ?string
    {
        return match ($this->reference_type) {
            Order::class => 'Order #'.Order::query()->withoutGlobalScopes()->whereKey($this->reference_id)->value('order_number'),
            default => $this->reference_type ? class_basename($this->reference_type).' #'.$this->reference_id : null,
        };
    }
}
