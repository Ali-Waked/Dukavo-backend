<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

class ApproveAiActionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
