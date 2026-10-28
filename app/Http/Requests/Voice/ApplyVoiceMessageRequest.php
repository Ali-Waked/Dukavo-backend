<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use App\Http\Requests\ApiRequest;

class ApplyVoiceMessageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', $this->tenantExists('products')],
            'items.*.product_variant_id' => ['required', 'integer', $this->tenantExists('product_variants')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
