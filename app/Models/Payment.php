<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One online payment attempt.
 *
 * The amount is copied from the server-priced order at creation and never
 * updated from a browser or provider payload. A successful row is the only
 * evidence that may mark an order paid.
 */
#[Fillable(['tenant_id', 'store_id', 'order_id', 'provider_id', 'store_payment_account_id', 'attempt_number', 'status', 'payment_method_code', 'amount', 'currency', 'client_reference', 'provider_reference', 'checkout_url', 'failure_reason', 'metadata', 'expires_at', 'paid_at'])]
class Payment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'attempt_number' => 'integer',
            'amount' => 'decimal:4',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<PaymentProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }

    /**
     * @return BelongsTo<StorePaymentAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StorePaymentAccount::class, 'store_payment_account_id');
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === PaymentAttemptStatus::Succeeded;
    }

    public function allowsRetry(): bool
    {
        return ! $this->isSucceeded() && $this->status->allowsRetry();
    }

    public function maskedReference(): ?string
    {
        $reference = trim((string) $this->provider_reference);

        if ($reference === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($reference) - 6)).substr($reference, -6);
    }
}
