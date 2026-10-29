<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock movement between two stores of the same tenant.
 *
 * The transfer row is the decision, the two linked ledger entries are the
 * effect. Keeping both means an auditor can see that -4 in Store A and +4 in
 * Store B were one action rather than an unexplained pair of corrections.
 */
#[Fillable([
    'tenant_id',
    'from_store_id',
    'to_store_id',
    'product_id',
    'product_variant_id',
    'quantity',
    'note',
    'user_id',
    'idempotency_key',
    'from_movement_id',
    'to_movement_id',
])]
class StockTransfer extends Model
{
    /** @use HasFactory<StockTransferFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'from_movement_id' => 'integer',
            'to_movement_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<InventoryMovement, $this>
     */
    public function fromMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'from_movement_id');
    }

    /**
     * @return BelongsTo<InventoryMovement, $this>
     */
    public function toMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'to_movement_id');
    }
}
