<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use App\Services\Security\AuthSessionService;
use Illuminate\Http\Request;

/**
 * The JSON body of a completed sign-in.
 *
 * The password step and the second-factor step both end here, so a client sees
 * exactly one authenticated response shape. Keeping it out of the Fortify
 * response class lets the challenge controller produce the same payload without
 * duplicating the token contract.
 */
final class SessionPayload
{
    public function __construct(private readonly AuthSessionService $sessions) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Request $request, string $method): array
    {
        $started = $this->sessions->start($user, $request, $method);

        return [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
            ],
            'token' => $started['token'],
            'token_type' => 'Bearer',
            'device' => $started['device'],
            'is_new_device' => $started['is_new_device'],
            'two_factor' => false,
        ];
    }
}
