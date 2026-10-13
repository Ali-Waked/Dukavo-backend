<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Requests\ApiRequest;

/**
 * A self-service password change.
 *
 * `current_password` is required and checked in the service: knowing the old
 * password is what separates the account owner from someone who merely found an
 * unlocked phone. The new password obeys the same rules as registration, so the
 * two entry points can never disagree about what is strong enough.
 */
class UpdatePasswordRequest extends ApiRequest
{
    use PasswordValidationRules;

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
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ];
    }
}
