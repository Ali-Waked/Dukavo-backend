<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Http\Requests\ApiRequest;

class SnoozeRecoveryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'until' => ['required', 'date', 'after:now'],
        ];
    }
}
