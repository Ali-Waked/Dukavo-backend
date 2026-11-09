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
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'method_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'method_name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'method_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'method_description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'delivery_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'delivery_instructions_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'translations' => ['sometimes', 'nullable', 'array'],
            'translations.*' => ['array'],
            'translations.*.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'translations.*.method_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'translations.*.method_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'translations.*.delivery_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'translations.*.terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
