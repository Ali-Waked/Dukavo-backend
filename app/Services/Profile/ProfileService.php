<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Users\UserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Everything a user may change about their *own* account.
 *
 * Kept apart from {@see UserService}, which is an
 * administrator editing someone else with different rules (role changes, forced
 * password reset, status). The separation is what stops "edit my profile" from
 * quietly growing the powers of "manage a teammate".
 */
class ProfileService
{
    private const AVATAR_DISK = 'public';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Update the fields a user owns. Email and status are not here on purpose:
     * the first needs a verification workflow Dukavo does not have, and the
     * second is an administrative decision, never a self-service one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $before = $user->only(['name', 'phone', 'locale']);

        $user->fill($attributes)->save();

        $this->audit->recordChanges(AuditAction::Updated, $user, $before, $user->only(['name', 'phone', 'locale']), actor: $user);

        return $user;
    }

    /**
     * Replace the avatar. The old file is removed first so a re-upload does not
     * accumulate orphaned images on the disk.
     */
    public function storeAvatar(User $user, UploadedFile $file): User
    {
        $this->deleteAvatarFile($user);

        $path = $file->store('avatars/'.$user->getKey(), self::AVATAR_DISK);

        $user->forceFill(['avatar_path' => $path])->save();

        $this->audit->record(AuditAction::Updated, $user, [
            'old' => ['avatar_path' => null],
            'new' => ['avatar_path' => $path],
        ], $user);

        return $user;
    }

    public function deleteAvatar(User $user): User
    {
        if ($user->avatar_path === null) {
            return $user;
        }

        $previous = $user->avatar_path;
        $this->deleteAvatarFile($user);

        $user->forceFill(['avatar_path' => null])->save();

        $this->audit->record(AuditAction::Updated, $user, [
            'old' => ['avatar_path' => $previous],
            'new' => ['avatar_path' => null],
        ], $user);

        return $user;
    }

    /**
     * Change the password, but only after proving the caller knows the current
     * one. Every other signed-in device is signed out: a password change is the
     * moment a user expects a stolen session to stop working.
     */
    public function updatePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        // The `hashed` cast on the model hashes this on assignment.
        $user->forceFill(['password' => $newPassword])->save();

        $this->revokeOtherTokens($user);

        $this->audit->record(AuditAction::Updated, $user, [
            'new' => ['credential' => 'password_updated'],
        ], $user);
    }

    /**
     * Merge a partial preference update over what is already stored, so a client
     * toggling one switch never has to resend the whole set.
     *
     * @param  array<string, mixed>  $submitted
     */
    public function updateNotificationPreferences(User $user, array $submitted): User
    {
        $preferences = $user->notificationPreferences();

        foreach (User::NOTIFICATION_CHANNELS as $channel => $events) {
            foreach ($events as $event) {
                if (array_key_exists($channel, $submitted) && is_array($submitted[$channel]) && array_key_exists($event, $submitted[$channel])) {
                    $preferences[$channel][$event] = (bool) $submitted[$channel][$event];
                }
            }
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();

        return $user;
    }

    /**
     * Revoke every token except the one making the request, so the device in hand
     * stays signed in while others are cut off. When the caller authenticated by
     * session (no personal access token), every token belongs to another device
     * and all are revoked.
     */
    public function revokeOtherTokens(User $user): int
    {
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            return $user->tokens()->whereKeyNot($current->getKey())->delete();
        }

        return $user->tokens()->delete();
    }

    private function deleteAvatarFile(User $user): void
    {
        if ($user->avatar_path !== null && $user->avatar_path !== '') {
            Storage::disk(self::AVATAR_DISK)->delete($user->avatar_path);
        }
    }
}
