<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventStatus;
use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Emails\AccountEmailService;
use App\Support\Auth\DeviceName;
use App\Support\Http\RequestCountry;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mint, describe and revoke the sessions an account holds.
 *
 * A session is one Sanctum personal access token. Minting lives here rather than
 * in the login response so the password step, the second-factor step and any
 * future client share the same device metadata, new-device warning and security
 * event. Revocation always starts from `$user->tokens()`, so a token id from a
 * request can never reach another account's session.
 */
final class AuthSessionService
{
    public function __construct(
        private readonly SecurityEventLogger $events,
        private readonly AccountEmailService $emails,
        private readonly RequestCountry $country,
    ) {}

    /**
     * Mint the real session token for a fully authenticated user.
     *
     * @return array{token: string, access_token: PersonalAccessToken, device: string, is_new_device: bool}
     */
    public function start(User $user, Request $request, string $method = 'password'): array
    {
        $device = DeviceName::for($request);
        $isNewDevice = ! $user->tokens()->where('name', $device)->exists();

        $newToken = $user->createToken($device);
        $token = $newToken->accessToken;

        $this->describe($token, $request);

        $user->forceFill(['last_login_at' => now()])->save();

        if ($isNewDevice) {
            $this->emails->send(
                user: $user,
                template: 'new_device',
                data: [
                    'device' => $device,
                    'time' => now()->format('Y-m-d H:i'),
                    'ip_address' => (string) $request->ip(),
                ],
                actionUrl: '/settings/security',
            );
        }

        $this->events->record($user, SecurityEventType::LoginSuccessful, SecurityEventStatus::Success, [
            'device' => $device,
            'method' => $method,
            'is_new_device' => $isNewDevice,
        ], $request);

        return [
            'token' => $newToken->plainTextToken,
            'access_token' => $token,
            'device' => $device,
            'is_new_device' => $isNewDevice,
        ];
    }

    /**
     * Persist where and how a token was first used.
     */
    public function describe(PersonalAccessToken $token, Request $request): void
    {
        $token->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
            'country_code' => $this->country->from($request),
        ])->save();
    }

    /**
     * Revoke one session owned by the user.
     */
    public function revoke(User $user, string $tokenId, Request $request): bool
    {
        $session = $user->tokens()->whereKey($tokenId)->first();

        if ($session === null) {
            return false;
        }

        $name = $session->name;
        $session->delete();

        $this->events->record($user, SecurityEventType::SessionRevoked, SecurityEventStatus::Success, [
            'session' => $name,
        ], $request);

        return true;
    }

    /**
     * Revoke every token except the caller's own.
     *
     * A bearer request has a personal access token to keep. A cookie-authenticated
     * request carries Sanctum's transient token, so every stored token belongs to
     * another device and all are revoked.
     */
    public function revokeOthers(User $user, Request $request): int
    {
        $current = $user->currentAccessToken();

        $count = $current instanceof PersonalAccessToken
            ? $user->tokens()->whereKeyNot($current->getKey())->delete()
            : $user->tokens()->delete();

        if ($count > 0) {
            $this->events->record($user, SecurityEventType::SessionsRevoked, SecurityEventStatus::Success, [
                'count' => $count,
            ], $request);
        }

        return $count;
    }

    /**
     * Revoke every token, used when credentials change. The caller's current
     * token is preserved only if `$exceptCurrent` is true.
     */
    public function revokeAll(User $user, bool $exceptCurrent = false): int
    {
        if (! $exceptCurrent) {
            return $user->tokens()->delete();
        }

        $current = $user->currentAccessToken();

        return $current instanceof PersonalAccessToken
            ? $user->tokens()->whereKeyNot($current->getKey())->delete()
            : $user->tokens()->delete();
    }
}
