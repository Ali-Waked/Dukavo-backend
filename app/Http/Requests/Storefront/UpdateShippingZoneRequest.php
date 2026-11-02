<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Http\Requests\ApiRequest;

class UpdateShippingZoneRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'areas' => ['sometimes', 'nullable', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
            'fee' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'free_shipping_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
