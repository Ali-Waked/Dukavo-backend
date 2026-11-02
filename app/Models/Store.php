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
#[Fillable(['tenant_id', 'name', 'slug', 'description', 'logo_url', 'status', 'email', 'phone', 'currency', 'currency_decimals', 'currency_updated_at', 'timezone', 'is_default', 'storefront_enabled', 'storefront_settings', 'payment_settings'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'slug', 'description', 'logo_url', 'status', 'currency', 'currency_decimals', 'timezone', 'is_default', 'storefront_enabled'];
    }

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'is_default' => 'boolean',
            'storefront_enabled' => 'boolean',
            'storefront_settings' => 'array',
            'payment_settings' => 'array',
            'currency_decimals' => 'integer',
            'currency_updated_at' => 'datetime',
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

    /**
     * @return HasMany<ShippingZone, $this>
     */
    public function shippingZones(): HasMany
    {
        return $this->hasMany(ShippingZone::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StoreStatus::Active);
    }
}
