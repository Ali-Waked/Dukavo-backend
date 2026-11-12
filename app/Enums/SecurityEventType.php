<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account-level security facts a person is allowed to review.
 *
 * This is deliberately narrower than {@see AuditAction}: it describes what
 * happened to a sign-in or credential, not every business change in a tenant.
 */
enum SecurityEventType: string
{
    case LoginSuccessful = 'login_successful';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordChanged = 'password_changed';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorChallengeFailed = 'two_factor_challenge_failed';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case SessionRevoked = 'session_revoked';
    case SessionsRevoked = 'sessions_revoked';
    case EmailVerified = 'email_verified';
    case PhoneVerified = 'phone_verified';
    case PreferencesUpdated = 'preferences_updated';

    public function label(): string
    {
        return match ($this) {
            self::LoginSuccessful => 'Successful sign-in',
            self::LoginFailed => 'Failed sign-in attempt',
            self::Logout => 'Signed out',
            self::PasswordChanged => 'Password changed',
            self::TwoFactorEnabled => 'Two-factor authentication enabled',
            self::TwoFactorDisabled => 'Two-factor authentication disabled',
            self::TwoFactorChallengeFailed => 'Two-factor verification failed',
            self::RecoveryCodesRegenerated => 'Recovery codes regenerated',
            self::SessionRevoked => 'Session revoked',
            self::SessionsRevoked => 'Other sessions revoked',
            self::EmailVerified => 'Email verified',
            self::PhoneVerified => 'Phone verified',
            self::PreferencesUpdated => 'Account preferences updated',
        };
    }

    /**
     * A stable group the interface can use for icons and filtering.
     */
    public function category(): string
    {
        return match ($this) {
            self::LoginSuccessful, self::LoginFailed, self::Logout, self::TwoFactorChallengeFailed => 'authentication',
            self::PasswordChanged, self::TwoFactorEnabled, self::TwoFactorDisabled, self::RecoveryCodesRegenerated => 'security',
            self::SessionRevoked, self::SessionsRevoked => 'sessions',
            self::EmailVerified, self::PhoneVerified, self::PreferencesUpdated => 'account',
        };
    }
}
