<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Http\Requests\ApiRequest;

class DoNotContactRecoveryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
