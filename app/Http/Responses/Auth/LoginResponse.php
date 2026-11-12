<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Models\User;
use App\Services\Security\TwoFactorService;
use App\Support\Auth\SessionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * The JSON answer to a successful password check.
 *
 * Fortify's stock response is `{"two_factor": false}` with the session cookie as
 * the only credential. Dukavo's SPA and mobile client authenticate with a
 * Sanctum bearer token instead, so a completed sign-in returns one inside the
 * application's own `{ success, data }` envelope.
 *
 * When the account has a confirmed second factor, no token is minted here. The
 * response carries only a short-lived challenge token; the real session is
 * created after `/auth/two-factor-challenge` verifies the code. That ordering is
 * what makes the second factor meaningful rather than decorative.
 */
class LoginResponse implements LoginResponseContract
{
    public function __construct(
        private readonly SessionPayload $payload,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function toResponse($request)
    {
        if (! $request->wantsJson()) {
            return redirect()->intended('/dashboard');
        }

        /** @var Request $request */
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse([
                'success' => false,
                'message' => __('Authentication failed.'),
            ], 401);
        }

        if ($user->hasTwoFactorEnabled()) {
            // Fortify's password pipeline has already signed the user into the
            // session guard by the time this response runs. For a protected
            // account that session must not authenticate anything until the
            // second factor is verified, or the cookie would bypass the factor
            // entirely. The challenge token below is the only credential the
            // client receives at this stage.
            auth()->guard((string) config('fortify.guard', 'web'))->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $challenge = $this->twoFactor->createChallenge($user, $request);

            return new JsonResponse([
                'success' => true,
                'message' => __('Enter your authentication code.'),
                'data' => [
                    'two_factor_required' => true,
                    ...$challenge,
                ],
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => __('Signed in.'),
            'data' => $this->payload->for($user, $request, 'password'),
        ]);
    }
}
