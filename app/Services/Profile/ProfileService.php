<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\AuditAction;
use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Emails\AccountEmailService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\NotificationIntent;
use App\Services\Notifications\UnsubscribeService;
use App\Services\Security\AuthSessionService;
use App\Services\Security\SecurityEventLogger;
use App\Services\Users\UserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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

    /** Profile fields that count as an account preference change. */
    private const PREFERENCE_FIELDS = [
        'locale', 'preferred_currency', 'country_code', 'city', 'timezone',
        'theme', 'date_format', 'time_format', 'phone_country_code',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountEmailService $accountEmails,
        private readonly NotificationDispatcher $notifications,
        private readonly UnsubscribeService $consent,
        private readonly AuthSessionService $sessions,
        private readonly SecurityEventLogger $securityEvents,
    ) {}

    /**
     * Update the fields a user owns. Email and status are not here on purpose:
     * the first needs a verification workflow Dukavo does not have, and the
     * second is an administrative decision, never a self-service one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $tracked = ['name', 'phone', 'locale', 'preferred_currency', ...self::PREFERENCE_FIELDS];
        $before = $user->only(array_values(array_unique($tracked)));

        $user->fill($attributes)->save();

        $after = $user->only(array_values(array_unique($tracked)));

        $this->audit->recordChanges(AuditAction::Updated, $user, $before, $after, actor: $user);

        $changedPreferences = array_filter(
            self::PREFERENCE_FIELDS,
            static fn (string $field): bool => ($before[$field] ?? null) !== ($after[$field] ?? null),
        );

        if ($changedPreferences !== []) {
            $this->securityEvents->record($user, SecurityEventType::PreferencesUpdated, metadata: [
                'fields' => array_values($changedPreferences),
            ]);
        }

        return $user;
    }

    public function storeAvatar(User $user, UploadedFile $file): User
    {
        $previous = $user->avatar_path;
        $path = $file->store('avatars/'.$user->getKey(), self::AVATAR_DISK);

        if ($path === false) {
            throw new RuntimeException('The avatar could not be stored.');
        }

        try {
            $user->forceFill(['avatar_path' => $path])->save();
        } catch (\Throwable $exception) {
            $this->deleteAvatarPath($path);

            throw $exception;
        }

        $this->audit->record(AuditAction::Updated, $user, [
            'old' => ['avatar_path' => $previous],
            'new' => ['avatar_path' => $path],
        ], $user);

        if ($previous !== null && $previous !== $path) {
            $this->deleteAvatarPath($previous);
        }

        return $user;
    }

    public function deleteAvatar(User $user): User
    {
        if ($user->avatar_path === null) {
            return $user;
        }

        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        $this->audit->record(AuditAction::Updated, $user, [
            'old' => ['avatar_path' => $previous],
            'new' => ['avatar_path' => null],
        ], $user);

        $this->deleteAvatarPath($previous);

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
        $user->forceFill([
            'password' => $newPassword,
            'password_changed_at' => now(),
        ])->save();

        $this->sessions->revokeAll($user, exceptCurrent: true);

        $this->audit->record(AuditAction::Updated, $user, [
            'new' => ['credential' => 'password_updated'],
        ], $user);

        $this->securityEvents->record($user, SecurityEventType::PasswordChanged);

        $this->accountEmails->send(
            user: $user,
            template: 'password_changed',
            actionUrl: '/settings/security',
        );

        $tenant = $user->tenants()->orderBy('tenants.id')->first();

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: NotificationEventCatalog::TENANT_SECURITY_PASSWORD_RESET,
            tenantId: $tenant?->getKey(),
            dedupeKey: 'security.password.changed:'.$user->getKey().':'.now()->timestamp,
            actionUrl: '/settings/security',
            recipientIds: [(int) $user->getKey()],
        ));
    }

    /**
     * Merge a partial preference update over what is already stored, so a client
     * toggling one switch never has to resend the whole set.
     *
     * The merge deliberately starts from the raw column rather than the resolved
     * set: writing the resolved values back would freeze today's role-aware
     * defaults into the account, so a later role change could never move a
     * switch the user never touched.
     *
     * @param  array<string, mixed>  $submitted
     */
    public function updateNotificationPreferences(User $user, array $submitted): User
    {
        $preferences = $user->notification_preferences ?? [];
        $consentChanges = [];

        foreach (User::NOTIFICATION_CHANNELS as $channel => $events) {
            $preferences[$channel] ??= [];

            foreach ($events as $event) {
                if (array_key_exists($channel, $submitted) && is_array($submitted[$channel]) && array_key_exists($event, $submitted[$channel])) {
                    $newValue = (bool) $submitted[$channel][$event];

                    if ($event === UnsubscribeService::CATEGORY) {
                        $oldValue = (bool) ($preferences[$channel][$event] ?? false);

                        if ($newValue !== $oldValue) {
                            $consentChanges[$channel] = $newValue;
                        }
                    }

                    $preferences[$channel][$event] = $newValue;
                }
            }
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();

        foreach ($consentChanges as $channel => $granted) {
            $this->consent->record(
                user: $user,
                channel: $channel,
                category: UnsubscribeService::CATEGORY,
                action: $granted ? 'granted' : 'revoked',
                source: 'settings',
                request: request(),
            );
        }

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
        return $this->sessions->revokeOthers($user, request());
    }

    private function deleteAvatarPath(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::AVATAR_DISK)->delete($path);
        }
    }
}
