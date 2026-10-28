<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Http\Requests\ApiRequest;

class AssignRecoveryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }
}
