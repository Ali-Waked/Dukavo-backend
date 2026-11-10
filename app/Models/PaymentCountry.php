<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A market Dukavo can sell into, administered from the platform.
 *
 * The country is the merchant's legal onboarding market and the settlement
 * market. It is deliberately not the customer's card-issuing country: a shopper
 * may pay with a foreign card, but eligibility is decided by where the merchant
 * is established.
 */
#[Fillable(['code', 'name', 'name_ar', 'currency', 'is_active', 'sort_order'])]
class PaymentCountry extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<PaymentProviderCountry, $this>
     */
    public function providerCoverage(): HasMany
    {
        return $this->hasMany(PaymentProviderCountry::class, 'country_code', 'code');
    }

    public function localizedName(?string $locale = null): string
    {
        return str_starts_with(strtolower((string) $locale), 'ar')
            ? $this->name_ar
            : $this->name;
    }
}
