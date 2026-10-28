<?php

declare(strict_types=1);

namespace App\Http\Requests\Bundle;

use App\Http\Requests\ApiRequest;
use App\Models\Store;

class PreviewBundleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', $this->tenantExists(Store::class)],
            'expected_catalog_fingerprint' => ['nullable', 'string', 'size:64'],
        ];
    }
}
