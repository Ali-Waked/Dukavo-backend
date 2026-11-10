<?php

declare(strict_types=1);

namespace App\Http\Resources\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant-safe payment attempt view.
 *
 * The full client reference and raw provider payloads are not exposed. The
 * provider charge reference is masked; support can still identify the tail of
 * it without the API becoming a reference oracle.
 *
 * @property-read Payment $resource
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'store_id' => $this->store_id,
            'attempt_number' => (int) $this->attempt_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'method_code' => $this->payment_method_code,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'provider' => [
                'code' => $this->provider?->code,
                'name' => $this->provider?->name,
            ],
            'reference' => $this->maskedReference(),
            'checkout_url' => $this->status->isSucceeded() ? null : $this->checkout_url,
            'failure_reason' => $this->failure_reason,
            'retry_allowed' => $this->allowsRetry(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'transactions' => PaymentTransactionResource::collection($this->whenLoaded('transactions')),
            'refunds' => PaymentRefundResource::collection($this->whenLoaded('refunds')),
        ];
    }
}
