<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StoreStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use App\Support\LocalizedText;
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
#[Fillable(['tenant_id', 'name', 'name_ar', 'slug', 'description', 'description_ar', 'logo_url', 'status', 'email', 'phone', 'country', 'currency', 'currency_decimals', 'currency_updated_at', 'timezone', 'enabled_locales', 'is_default', 'storefront_enabled', 'storefront_settings', 'payment_settings'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasTranslations;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'name_ar', 'slug', 'description', 'description_ar', 'logo_url', 'status', 'email', 'phone', 'country', 'currency', 'currency_decimals', 'timezone', 'enabled_locales', 'is_default', 'storefront_enabled'];
    }

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'is_default' => 'boolean',
            'storefront_enabled' => 'boolean',
            'storefront_settings' => 'array',
            'payment_settings' => 'array',
            'enabled_locales' => 'array',
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

    /**
     * @return HasMany<StorePaymentAccount, $this>
     */
    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(StorePaymentAccount::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StoreStatus::Active);
    }

    /** The best available store name for the requested locale. */
    public function localizedName(string $locale): string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('name', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return (string) LocalizedText::pick($this->name, $this->name_ar, $locale);
    }

    /** The best available description, or null when neither language has one. */
    public function localizedDescription(string $locale): ?string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('description', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return LocalizedText::pick($this->description, $this->description_ar, $locale);
    }
}
