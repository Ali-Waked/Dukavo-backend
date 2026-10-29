<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One variant in a cart.
 *
 * Name, SKU and unit price are snapshots for display; they are refreshed from
 * the catalogue whenever the cart is mutated or confirmed, so a stale browser
 * never locks in an old price.
 */
#[Fillable([
    'tenant_id', 'cart_id', 'product_id', 'product_variant_id', 'name', 'sku',
    'unit_price', 'quantity', 'discount_total', 'line_total',
])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'discount_total' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
