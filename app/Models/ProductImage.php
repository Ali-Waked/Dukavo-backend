<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image attached to a product, optionally narrowed to a single variant.
 * `path` is stored relative to a disk; the public URL is built by the
 * resource so the storage driver stays an implementation detail.
 */
#[Fillable(['tenant_id', 'product_id', 'product_variant_id', 'disk', 'path', 'alt_text', 'is_primary', 'position'])]
class ProductImage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
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
