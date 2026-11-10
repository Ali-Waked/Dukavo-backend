<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A capability the provider adapter actually implements.
 *
 * Capability names are provider-independent strings such as `charges`,
 * `refunds`, `webhooks`, `status` and `connection_test`.
 */
#[Fillable(['provider_id', 'capability'])]
class PaymentProviderCapability extends Model
{
    /**
     * @return BelongsTo<PaymentProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }
}
