<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Models\User;
use App\Support\Auth\DeviceName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * The JSON answer to a successful login.
 *
 * Fortify's stock response is `{"two_factor": false}` with the session cookie as
 * the only credential. Dukavo's SPA authenticates with a Sanctum bearer token
 * instead, so a successful login mints one and returns it inside the application's
 * own `{ success, data }` envelope — the frontend only ever has to understand one
 * response shape.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if (! $request->wantsJson()) {
            return redirect()->intended('/dashboard');
        }

        /** @var Request $request */
        $user = $request->user();

        // Naming the token after the device is what makes the "signed-in
        // devices" list on the security screen readable.
        $token = $user instanceof User ? $user->createToken(DeviceName::for($request))->plainTextToken : null;

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return new JsonResponse([
            'success' => true,
            'message' => __('Signed in.'),
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
        ]);
    }
}
