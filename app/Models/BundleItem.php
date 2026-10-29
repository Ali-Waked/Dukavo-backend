<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BundleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One concrete variant inside a merchant-curated bundle.
 */
#[Fillable([
    'tenant_id', 'bundle_id', 'product_id', 'product_variant_id', 'quantity', 'position',
])]
class BundleItem extends Model
{
    /** @use HasFactory<BundleItemFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Bundle, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
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
