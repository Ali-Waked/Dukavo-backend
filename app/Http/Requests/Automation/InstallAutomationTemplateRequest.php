<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Http\Requests\ApiRequest;
use App\Models\Store;

final class InstallAutomationTemplateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists(Store::class)],
            'configuration' => ['sometimes', 'array', 'max:20'],
        ];
    }
}
