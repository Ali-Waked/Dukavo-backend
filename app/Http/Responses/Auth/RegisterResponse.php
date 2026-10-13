<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Models\User;
use App\Support\Auth\DeviceName;
use App\Support\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * The JSON answer to a successful registration.
 *
 * Mirrors {@see LoginResponse} on purpose: the SPA becomes authenticated in
 * the same breath it signs up. Minting the bearer token here means the very
 * next request can already be authenticated, instead of forcing a login round
 * trip that makes registration feel like two steps.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        if (! $request->wantsJson()) {
            // No Blade register screen exists (API-only application); a browser
            // form submission lands on the sign-in page instead of a dead URL.
            return redirect('/login');
        }

        /** @var Request $request */
        $user = $request->user();

        $token = $user instanceof User ? $user->createToken(DeviceName::for($request))->plainTextToken : null;

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        // 201: the account is a newly created resource, matching what the
        // endpoint answered before this response existed.
        return new JsonResponse([
            'success' => true,
            'message' => __('Account created.'),
            'data' => [
                'user' => $user instanceof User ? [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                ] : null,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], Response::CREATED);
    }
}
