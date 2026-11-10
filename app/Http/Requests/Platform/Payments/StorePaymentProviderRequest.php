<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Enums\PaymentProviderStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class StorePaymentProviderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('payment_providers', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::enum(PaymentProviderStatus::class)],
            'integration_enabled' => ['sometimes', 'boolean'],
            'adapter' => ['nullable', 'string', 'max:190'],
            'docs_url' => ['nullable', 'url', 'max:255'],
            'webhook_docs_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'last_verified_at' => ['nullable', 'date'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:40'],
        ];
    }
}
