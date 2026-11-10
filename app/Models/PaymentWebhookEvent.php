<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentWebhookStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw evidence of a provider callback.
 *
 * This model is deliberately not tenant-scoped: a callback for an unknown
 * reference must still be recordable without inventing a tenant. Queries in
 * application code always constrain it by tenant, payment or provider.
 */
#[Fillable(['provider_id', 'store_payment_account_id', 'payment_id', 'tenant_id', 'store_id', 'event_id', 'event_type', 'status', 'signature_valid', 'payload', 'headers', 'failure_reason', 'processed_at'])]
class PaymentWebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PaymentWebhookStatus::class,
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
