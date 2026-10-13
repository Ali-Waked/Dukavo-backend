<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StoreStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A storefront / outlet belonging to a tenant.
 *
 * Inventory is tracked per store, which is why a tenant always owns at least
 * one store (created together with the tenant).
 */
#[Fillable(['tenant_id', 'name', 'slug', 'status', 'email', 'phone', 'currency', 'timezone', 'is_default'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'slug', 'status', 'currency', 'timezone', 'is_default'];
    }

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Inventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
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
        $query->where('status', StoreStatus::Active);
    }
}
