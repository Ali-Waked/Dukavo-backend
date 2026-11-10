<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('payment_methods', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
            'description_ar' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
