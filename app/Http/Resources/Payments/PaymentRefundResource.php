<?php

declare(strict_types=1);

namespace App\Http\Resources\Payments;

use App\Models\PaymentRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PaymentRefund $resource
 */
class PaymentRefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'order_id' => $this->order_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'provider_refund_id' => $this->provider_refund_id,
            'failure_reason' => $this->failure_reason,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
