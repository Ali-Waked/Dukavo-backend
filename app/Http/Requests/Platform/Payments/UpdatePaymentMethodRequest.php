<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Http\Requests\ApiRequest;

class UpdatePaymentMethodRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'string', 'max:255'],
            'description_ar' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
