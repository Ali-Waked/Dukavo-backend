<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\ApiRequest;

class UpdateCustomerSummaryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'summary' => ['sometimes', 'string', 'max:5000'],
            'highlights' => ['nullable', 'array', 'max:10'],
            'highlights.*' => ['string', 'max:200'],
            'risks' => ['nullable', 'array', 'max:10'],
            'risks.*' => ['string', 'max:200'],
            'opportunities' => ['nullable', 'array', 'max:10'],
            'opportunities.*' => ['string', 'max:200'],
        ];
    }
}
