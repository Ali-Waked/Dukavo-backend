<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;

/**
 * The broker status is translated into a message the user can act on. The raw
 * translation key is never returned because it is not useful to a client.
 */
class FailedPasswordResetResponse implements FailedPasswordResetResponseContract
{
    public function toResponse($request, $status, $message = null): JsonResponse
    {
        $errors = match ($status) {
            PasswordBroker::INVALID_TOKEN => ['token' => ['This password reset link is invalid or has expired.']],
            PasswordBroker::INVALID_USER => ['email' => ['We could not find an account for that email address.']],
            PasswordBroker::RESET_THROTTLED => ['email' => ['Please wait before trying again.']],
            default => ['email' => ['We could not reset your password.']],
        };

        throw ValidationException::withMessages($errors);
    }
}
