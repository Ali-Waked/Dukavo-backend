<?php

declare(strict_types=1);

namespace App\Http\Requests\Bundle;

use App\Http\Requests\ApiRequest;
use App\Models\Store;

class ExplainBundleRecommendationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', $this->tenantExists('products')],
            'partner_product_id' => ['required', 'integer', 'different:product_id', $this->tenantExists('products')],
            'store_id' => ['nullable', 'integer', $this->tenantExists(Store::class)],
        ];
    }
}
