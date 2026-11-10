<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Enums\PaymentProviderStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentProviderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(PaymentProviderStatus::class)],
            'integration_enabled' => ['sometimes', 'boolean'],
            'adapter' => ['sometimes', 'nullable', 'string', 'max:190'],
            'docs_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'webhook_docs_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'last_verified_at' => ['sometimes', 'nullable', 'date'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:40'],
        ];
    }
}
