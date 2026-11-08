<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ShippingZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery price for one store, optionally narrowed to a city and a set of
 * areas inside that city.
 *
 * A NULL `city` means the zone covers every city the store serves. A non-empty
 * `areas` list limits the zone to those named areas; an empty list means the
 * whole city. Fees are always resolved on the server from this table, never
 * accepted from a checkout payload.
 */
#[Fillable(['tenant_id', 'store_id', 'name', 'city', 'areas', 'fee', 'free_shipping_threshold', 'is_active', 'sort_order'])]
class ShippingZone extends Model
{
    /** @use HasFactory<ShippingZoneFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'city', 'areas', 'fee', 'free_shipping_threshold', 'is_active', 'sort_order'];
    }

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'fee' => 'decimal:4',
            'free_shipping_threshold' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
