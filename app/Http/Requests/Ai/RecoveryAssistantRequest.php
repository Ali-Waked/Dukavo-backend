<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

class RecoveryAssistantRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'instruction' => ['nullable', 'string', 'max:1500'],
        ];
    }
}
