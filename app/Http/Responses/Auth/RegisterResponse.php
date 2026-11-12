<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Models\User;
use App\Support\Auth\SessionPayload;
use App\Support\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * The JSON answer to a successful registration.
 *
 * Mirrors {@see LoginResponse} on purpose: the SPA becomes authenticated in the
 * same breath it signs up. Minting the bearer token through the same session
 * service means the device metadata, new-device notice and security event are
 * identical to a normal sign-in.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function __construct(private readonly SessionPayload $payload) {}

    public function toResponse($request)
    {
        if (! $request->wantsJson()) {
            return redirect('/login');
        }

        /** @var Request $request */
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse([
                'success' => false,
                'message' => __('Registration failed.'),
            ], 422);
        }

        return new JsonResponse([
            'success' => true,
            'message' => __('Account created.'),
            'data' => $this->payload->for($user, $request, 'registration'),
        ], Response::CREATED);
    }
}
