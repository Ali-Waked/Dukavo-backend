<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Payments;

use App\Http\Requests\ApiRequest;

class UpdatePaymentCountryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
