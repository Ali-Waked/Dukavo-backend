<?php

declare(strict_types=1);

namespace App\Services\Stores;

use App\Enums\StoreStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Currency\CurrencyCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Store (outlet) management.
 *
 * A tenant always has exactly one default store; demoting or deleting it is
 * refused so downstream code can rely on a default existing.
 */
class StoreService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CurrencyCatalog $currencies,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Store
    {
        try {
            return DB::transaction(function () use ($attributes): Store {
                $tenant = $this->tenants->required();

                // A supplied slug is used verbatim; blank falls back to the name.
                $slug = $attributes['slug'] ?? null;
                if ($slug === null || $slug === '') {
                    $slug = $this->uniqueSlug($tenant, $attributes['name']);
                }

                $currency = $this->currencies->supports($attributes['currency'] ?? null)
                    ? strtoupper((string) $attributes['currency'])
                    : ($this->currencies->supports($tenant->currency) ? strtoupper((string) $tenant->currency) : $this->currencies->default());

                $store = $tenant->stores()->create([
                    'name' => $attributes['name'],
                    'name_ar' => $attributes['name_ar'] ?? null,
                    'slug' => $slug,
                    'description' => $attributes['description'] ?? null,
                    'description_ar' => $attributes['description_ar'] ?? null,
                    'status' => $attributes['status'] ?? StoreStatus::Active,
                    'email' => $attributes['email'] ?? null,
                    'phone' => $attributes['phone'] ?? null,
                    'currency' => $currency,
                    'currency_decimals' => $this->currencies->decimals($currency),
                    'timezone' => $attributes['timezone'] ?? $tenant->timezone,
                    'is_default' => (bool) ($attributes['is_default'] ?? false),
                ]);

                if ($store->is_default) {
                    $this->demoteOthers($store);
                }

                return $store;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is already in use. Please choose another one.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Store $store, array $attributes): Store
    {
        // Clearing the slug keeps the current one; renaming without a slug
        // regenerates it from the new name.
        if (array_key_exists('slug', $attributes) && ($attributes['slug'] === null || $attributes['slug'] === '')) {
            unset($attributes['slug']);
        }

        if (array_key_exists('name', $attributes) && ! array_key_exists('slug', $attributes)) {
            $tenant = $this->tenants->required();
            $attributes['slug'] = $this->uniqueSlug($tenant, $attributes['name'], $store->getKey());
        }

        if (array_key_exists('is_default', $attributes) && ! $attributes['is_default'] && $store->is_default) {
            throw BusinessRuleViolation::because('A tenant must always keep a default store.');
        }

        // A base currency is not an ordinary editable field: changing it without
        // converting prices would relabel every amount. The dedicated currency
        // endpoint is the only path that may change it.
        if (array_key_exists('currency', $attributes)
            && strtoupper((string) $attributes['currency']) !== strtoupper((string) $store->currency)) {
            throw BusinessRuleViolation::because(
                'Use the store currency conversion endpoint to change the base currency.',
                ['currency' => $attributes['currency']],
                'currency_requires_conversion',
            );
        }

        unset($attributes['currency'], $attributes['currency_decimals']);

        try {
            return DB::transaction(function () use ($store, $attributes): Store {
                $store->fill($attributes)->save();

                if ($store->is_default) {
                    $this->demoteOthers($store);
                }

                return $store;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is already in use. Please choose another one.',
            ]);
        }
    }

    public function delete(Store $store): void
    {
        if ($store->is_default) {
            throw BusinessRuleViolation::because('The default store cannot be deleted.');
        }

        if ($store->inventories()->exists() || $store->orders()->exists()) {
            throw BusinessRuleViolation::because('This store still has inventory or orders. Deactivate it instead.');
        }

        $store->delete();
    }

    public function setDefault(Store $store): Store
    {
        return DB::transaction(function () use ($store): Store {
            $store->forceFill(['is_default' => true])->save();
            $this->demoteOthers($store);

            return $store;
        });
    }

    private function demoteOthers(Store $store): void
    {
        Store::query()
            ->whereKeyNot($store->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function uniqueSlug(Tenant $tenant, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while (Store::query()
            ->where('tenant_id', $tenant->getKey())
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
