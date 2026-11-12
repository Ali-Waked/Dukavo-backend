<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SecurityEventStatus;
use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Auth\Events\Failed;

/**
 * Record a failed credential check against a known account.
 *
 * Laravel's `Failed` event carries the attempted credentials. Only the email is
 * forwarded, never the password, and the logger filters credentials again. We
 * record this only for the Fortify login route so a password-confirmation check
 * on another screen is not mislabelled as a sign-in attempt.
 *
 * A failed attempt against an unknown address is not written: it has no account
 * to attach to, and the endpoint already rate-limits the caller.
 *
 * Dukavo authenticates through `Fortify::authenticateUsing`, so a wrong
 * password makes that callback return `null` and Laravel's `Failed` event has no
 * user attached. In that case the account is resolved from the submitted email,
 * which is the same lookup the callback performed and still touches only known
 * accounts.
 */
final class RecordFailedLogin
{
    public function __construct(private readonly SecurityEventLogger $events) {}

    public function handle(Failed $event): void
    {
        if (! request()->routeIs('login.store')) {
            return;
        }

        $email = $event->credentials['email'] ?? null;
        $user = $event->user instanceof User
            ? $event->user
            : (is_string($email) ? User::query()->where('email', $email)->first() : null);

        if (! $user instanceof User) {
            return;
        }

        $this->events->record($user, SecurityEventType::LoginFailed, SecurityEventStatus::Failure, [
            'email' => is_string($email) ? $email : null,
        ]);
    }
}
