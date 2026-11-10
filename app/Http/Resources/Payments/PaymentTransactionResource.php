<?php

declare(strict_types=1);

namespace App\Http\Resources\Payments;

use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ledger view. Payloads stay server-side because they can contain customer
 * details even after credentials have been stripped.
 *
 * @property-read PaymentTransaction $resource
 */
class PaymentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'provider_transaction_id' => $this->provider_transaction_id,
            'provider_status' => $this->provider_status,
            'failure_reason' => $this->failure_reason,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
