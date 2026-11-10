<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Http\Requests\ApiRequest;

/**
 * A merchant-authorised refund. The amount is bounded again inside the
 * service against the captured charge and earlier refunds.
 */
class RefundPaymentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:180'],
        ];
    }
}
