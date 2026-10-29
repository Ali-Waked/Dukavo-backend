<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order.
 *
 * `name` and `sku` are snapshots taken at purchase time; the nullable product
 * references let the catalogue change without rewriting order history.
 */
#[Fillable(['tenant_id', 'order_id', 'product_id', 'product_variant_id', 'name', 'sku', 'unit_price', 'unit_cost', 'cost_currency', 'cost_captured_at', 'quantity', 'discount_total', 'tax_total', 'line_total'])]
class OrderItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'cost_captured_at' => 'datetime',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'product_id' => 'integer',
            'product_variant_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
}
