<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class PaymentProviderMethodRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method_code' => ['required', 'string', 'max:40', Rule::exists('payment_methods', 'code')],
            'country_code' => ['nullable', 'string', 'size:2', 'uppercase', Rule::exists('payment_countries', 'code')],
            'source_id' => ['required', 'string', 'max:80'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'],
        ];
    }
}
