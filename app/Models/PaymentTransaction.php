<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable charge or refund call to a provider.
 *
 * There is intentionally no `updated_at`: a financial record is appended, not
 * edited. Request and response payloads are stored only after removing
 * credentials, so the ledger is useful without becoming a secret store.
 */
#[Fillable(['tenant_id', 'store_id', 'payment_id', 'order_id', 'provider_id', 'type', 'status', 'amount', 'currency', 'provider_transaction_id', 'provider_status', 'idempotency_key', 'request_payload', 'response_payload', 'failure_reason', 'processed_at', 'created_at'])]
class PaymentTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'status' => PaymentTransactionStatus::class,
            'amount' => 'decimal:4',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
