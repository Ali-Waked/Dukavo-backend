<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Save or replace a merchant provider connection.
 *
 * The secret is write-only: it is accepted here, encrypted on the model and
 * never returned by any endpoint.
 */
class StorePaymentConnectionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_code' => ['required', 'string', 'max:40', Rule::exists('payment_providers', 'code')],
            'environment' => ['required', 'string', Rule::in(['sandbox', 'live'])],
            'credentials' => ['required', 'array'],
            'credentials.secret_key' => ['required', 'string', 'max:500'],
            'credentials.public_key' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
            'merchant_reference' => ['nullable', 'string', 'max:190'],
        ];
    }
}
