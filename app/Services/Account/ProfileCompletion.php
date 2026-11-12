<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;

/**
 * A single definition of "how complete is this profile".
 *
 * The profile screen and the account overview must agree, so the calculation
 * lives here rather than in two resources. It reads only columns already on the
 * user row and performs no query.
 */
final class ProfileCompletion
{
    /**
     * @return array{percentage: int, completed: list<string>, missing: list<string>}
     */
    public function for(User $user): array
    {
        $checks = [
            'name' => is_string($user->name) && trim($user->name) !== '',
            'avatar' => $user->avatar_path !== null && $user->avatar_path !== '',
            'email_verified' => $user->email_verified_at !== null,
            'phone' => is_string($user->phone) && trim($user->phone) !== '',
            'phone_verified' => $user->phone_verified_at !== null,
            'bio' => is_string($user->bio) && trim($user->bio) !== '',
            'country' => $user->country_code !== null,
            'city' => is_string($user->city) && trim($user->city) !== '',
            'timezone' => is_string($user->timezone) && $user->timezone !== '',
            'language' => is_string($user->locale) && $user->locale !== '',
            'currency' => is_string($user->preferred_currency) && $user->preferred_currency !== '',
            'two_factor' => $user->hasTwoFactorEnabled(),
        ];

        $completed = array_keys(array_filter($checks));
        $missing = array_keys(array_filter($checks, static fn (bool $done): bool => ! $done));

        return [
            'percentage' => (int) round(count($completed) / count($checks) * 100),
            'completed' => $completed,
            'missing' => $missing,
        ];
    }
}
