<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

class MerchantCopilotRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:1000'],
            'store_id' => ['nullable', 'integer', $this->tenantExists('stores')],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
