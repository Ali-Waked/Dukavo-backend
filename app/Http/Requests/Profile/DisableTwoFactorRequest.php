<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiRequest;

class DisableTwoFactorRequest extends ApiRequest
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
            'password' => ['required', 'string'],
            // A current TOTP code or a one-time recovery code. The service
            // requires at least one valid factor in addition to the password.
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
