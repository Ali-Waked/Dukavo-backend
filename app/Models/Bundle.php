<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BundleDiscountType;
use App\Enums\BundleStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BundleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A merchant-curated collection sold together at a discount.
 *
 * A bundle is a proposal, not stock. Its lines are always concrete variants so
 * it can be priced and checked against one store's inventory without guessing.
 */
#[Fillable([
    'tenant_id', 'store_id', 'name', 'description', 'status', 'discount_type',
    'discount_value', 'currency',
])]
class Bundle extends Model
{
    /** @use HasFactory<BundleFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'status', 'discount_type', 'discount_value', 'currency'];
    }

    protected function casts(): array
    {
        return [
            'status' => BundleStatus::class,
            'discount_type' => BundleDiscountType::class,
            'discount_value' => 'decimal:2',
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
     * @return HasMany<BundleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class)->orderBy('position');
    }

    public function isSellable(): bool
    {
        return $this->status->isSellable();
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return $this->items->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', BundleStatus::Active);
    }
}
