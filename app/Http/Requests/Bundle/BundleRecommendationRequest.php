<?php

declare(strict_types=1);

namespace App\Http\Requests\Bundle;

use App\Http\Requests\ApiRequest;
use App\Models\Store;

class BundleRecommendationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', $this->tenantExists('products')],
            'store_id' => ['nullable', 'integer', $this->tenantExists(Store::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'expected_catalog_fingerprint' => ['nullable', 'string', 'size:64'],
        ];
    }
}
