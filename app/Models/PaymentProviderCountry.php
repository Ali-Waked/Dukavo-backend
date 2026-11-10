<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documented provider coverage for one country.
 *
 * Onboarding and transaction capability are separate facts. Tap closed new
 * merchant onboarding in Egypt and Jordan but still processes their existing
 * merchants, and this row is where that distinction lives.
 */
#[Fillable(['provider_id', 'country_code', 'settlement_currency', 'merchant_onboarding', 'transaction_capable', 'source_url', 'notes', 'verified_at'])]
class PaymentProviderCountry extends Model
{
    protected function casts(): array
    {
        return [
            'merchant_onboarding' => 'boolean',
            'transaction_capable' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }

    /**
     * @return BelongsTo<PaymentCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(PaymentCountry::class, 'country_code', 'code');
    }
}
