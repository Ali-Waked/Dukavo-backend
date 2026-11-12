<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\Security\TwoFactorService;
use App\Support\Auth\SessionPayload;
use Illuminate\Http\JsonResponse;

/**
 * Exchange a second-factor challenge for a real session token.
 *
 * This is the only endpoint that can turn a password-only login into an
 * authenticated one for a protected account. The challenge is single-use,
 * time-bound and attempt-limited, and the token is only minted after the code
 * verifies. A failed attempt reveals nothing about which part was wrong.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SessionPayload $payload,
    ) {}

    public function store(TwoFactorChallengeRequest $request): JsonResponse
    {
        $result = $this->twoFactor->verifyChallenge(
            (string) $request->validated('challenge_token'),
            $request->validated('code') === null ? null : (string) $request->validated('code'),
            $request->validated('recovery_code') === null ? null : (string) $request->validated('recovery_code'),
            $request,
        );

        return response()->json([
            'success' => true,
            'message' => __('Signed in.'),
            'data' => $this->payload->for($result['user'], $request, $result['method']),
        ]);
    }
}
