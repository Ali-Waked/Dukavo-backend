<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Http\Requests\ApiRequest;

final class ApplyAutomationTemplateUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
