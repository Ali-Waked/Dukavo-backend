<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One audited base-currency change for a store.
 *
 * The row is the receipt for a financial operation: it records the rate, where
 * the rate came from, when it was fetched, how many rows were converted and who
 * asked. Reporting can therefore convert historical order amounts with the same
 * rate the store used instead of today's arbitrary quote.
 */
#[Fillable([
    'tenant_id',
    'store_id',
    'from_currency',
    'to_currency',
    'exchange_rate',
    'rate_source',
    'rate_fetched_at',
    'status',
    'products_converted',
    'variants_converted',
    'bundles_converted',
    'shipping_zones_converted',
    'idempotency_key',
    'requested_by',
    'failure_reason',
    'completed_at',
])]
class CurrencyConversion extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:10',
            'rate_fetched_at' => 'datetime',
            'completed_at' => 'datetime',
            'products_converted' => 'integer',
            'variants_converted' => 'integer',
            'bundles_converted' => 'integer',
            'shipping_zones_converted' => 'integer',
        ];
    }
}
