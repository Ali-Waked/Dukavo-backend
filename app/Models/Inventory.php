<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Current stock position for one variant in one store.
 *
 * `quantity` is the physical on-hand count, `reserved_quantity` the part of it
 * committed to open orders. `available` is derived rather than stored so the
 * two quantities can never drift out of sync.
 */
#[Fillable(['tenant_id', 'store_id', 'product_id', 'product_variant_id', 'quantity', 'reserved_quantity', 'low_stock_threshold'])]
class Inventory extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function available(): int
    {
        return $this->quantity - $this->reserved_quantity;
    }

    public function isLowStock(): bool
    {
        return $this->low_stock_threshold !== null && $this->available() <= $this->low_stock_threshold;
    }

    /**
     * Rows at or below their low-stock threshold.
     *
     * The comparison uses available stock rather than on-hand quantity: units
     * already promised to open orders cannot serve a new customer, so ignoring
     * reservations would report healthy stock for a product that is about to
     * sell out.
     */
    #[Scope]
    protected function lowStock(Builder $query): void
    {
        $query->whereNotNull('low_stock_threshold')
            ->whereRaw('(quantity - reserved_quantity) <= low_stock_threshold');
    }
}
