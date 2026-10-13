<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses requests from accounts that may no longer sign in.
 *
 * Fortify checks a user's status when they authenticate, but a token issued
 * before an account was suspended stays valid until it expires. Suspension that
 * takes effect only at the next login is not suspension, so every authenticated
 * request re-checks the account and the tokens themselves are revoked when an
 * account is suspended.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Read the status from the database rather than trusting the resolved
        // user: that instance may have been loaded before the suspension, and a
        // decision made from a stale status is not enforcement.
        $status = User::query()->whereKey($user->getKey())->value('status');

        if ($status instanceof UserStatus ? $status->canAuthenticate() : $status === null) {
            return $next($request);
        }

        // Drop every token for this account so a suspended user cannot keep using
        // one issued before the suspension.
        $user->tokens()->delete();

        throw new AuthenticationException('This account is no longer active.');
    }
}
