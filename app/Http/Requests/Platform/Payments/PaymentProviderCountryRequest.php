<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class PaymentProviderCountryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_code' => ['required', 'string', 'size:2', 'uppercase', Rule::exists('payment_countries', 'code')],
            'settlement_currency' => ['required', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'merchant_onboarding' => ['sometimes', 'boolean'],
            'transaction_capable' => ['sometimes', 'boolean'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'verified_at' => ['nullable', 'date'],
        ];
    }
}
