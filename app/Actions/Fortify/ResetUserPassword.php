<?php

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $attributes = [
            'password' => Hash::make($input['password']),
        ];

        // Setting a password is how an invited account accepts its invitation.
        // It is the only transition that makes the invitation real, so it also
        // clears the pending state that keeps the dashboard closed.
        if ($user->status === UserStatus::Invited) {
            $attributes['status'] = UserStatus::Active;
        }

        $user->forceFill($attributes)->save();
    }
}
