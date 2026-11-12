<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concrete purchasable unit of a product (size, colour, weight, ...).
 *
 * Exactly one variant per product is flagged `is_default`; the catalogue
 * service enforces that invariant when variants are created or promoted.
 */
#[Fillable(['tenant_id', 'product_id', 'name', 'description', 'sku', 'barcode', 'price', 'cost', 'is_default', 'position'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use BelongsToTenant, HasFactory, HasTranslations;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'cost' => 'decimal:4',
            'is_default' => 'boolean',
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
     * @return HasMany<Inventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** The variant label in the requested language. */
    public function localizedName(string $locale): string
    {
        return (string) $this->translatedValueWithFallback('name', $locale, null, (string) $this->name);
    }

    /** The variant description in the requested language. */
    public function localizedDescription(string $locale): ?string
    {
        return $this->translatedValueWithFallback('description', $locale, null, $this->description);
    }
}
