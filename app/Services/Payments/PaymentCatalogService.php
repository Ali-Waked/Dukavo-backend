<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentCountry;
use App\Models\PaymentMethodDefinition;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCountry;
use Illuminate\Support\Collection;

/**
 * Read models for the platform payment catalogue.
 *
 * These queries never touch a merchant connection or credential. Platform
 * administrators manage availability, not secrets.
 */
class PaymentCatalogService
{
    /**
     * @return Collection<int, PaymentCountry>
     */
    public function countries(bool $activeOnly = false): Collection
    {
        return PaymentCountry::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, PaymentProvider>
     */
    public function providers(): Collection
    {
        return PaymentProvider::query()
            ->with(['countries.country', 'capabilities', 'methods.method'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, PaymentMethodDefinition>
     */
    public function methods(bool $activeOnly = false): Collection
    {
        return PaymentMethodDefinition::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, PaymentProviderCountry>
     */
    public function coverage(?int $providerId = null): Collection
    {
        return PaymentProviderCountry::query()
            ->when($providerId !== null, fn ($query) => $query->where('provider_id', $providerId))
            ->with(['provider', 'country'])
            ->orderBy('country_code')
            ->get();
    }
}
