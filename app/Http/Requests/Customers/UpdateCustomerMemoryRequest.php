<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\ApiRequest;

class UpdateCustomerMemoryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:100'],
            'value' => ['sometimes', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'max:50'],
            'source' => ['sometimes', 'string', 'max:50'],
            'confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'meta' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
            'review_at' => ['nullable', 'date'],
        ];
    }
}
