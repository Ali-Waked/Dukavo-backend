<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRefundStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Payments\PaymentRefundService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund requested against a captured charge.
 *
 * The store is tenant-owned, so the normal scope prevents one merchant from
 * reading another's refunds. The amount is never accepted above the remaining
 * refundable balance; see {@see PaymentRefundService}.
 */
#[Fillable(['tenant_id', 'store_id', 'payment_id', 'order_id', 'provider_id', 'store_payment_account_id', 'requested_by', 'status', 'amount', 'currency', 'reason', 'provider_refund_id', 'idempotency_key', 'failure_reason', 'processed_at'])]
class PaymentRefund extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => PaymentRefundStatus::class,
            'amount' => 'decimal:4',
            'processed_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
