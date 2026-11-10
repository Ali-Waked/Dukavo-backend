<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The provider-side source id for a method in a country.
 *
 * `source_id` is sent to the provider when a charge is created. It is chosen
 * server-side from this table and is never accepted from a checkout payload.
 */
#[Fillable(['provider_id', 'method_code', 'country_code', 'source_id', 'priority', 'is_active', 'config'])]
class PaymentProviderMethod extends Model
{
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'config' => 'array',
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
     * @return BelongsTo<PaymentMethodDefinition, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodDefinition::class, 'method_code', 'code');
    }
}
