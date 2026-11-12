<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiRequest;

class ConfirmTwoFactorRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16'],
        ];
    }
}
