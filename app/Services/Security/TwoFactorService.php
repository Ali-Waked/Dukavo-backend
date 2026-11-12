<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventStatus;
use App\Enums\SecurityEventType;
use App\Exceptions\BusinessRuleViolation;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Emails\AccountEmailService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * TOTP two-factor authentication for the person's own account.
 *
 * Fortify's provider is used only to generate and verify a TOTP code. Storage,
 * recovery codes, the login challenge and the disable/regenerate flows live here
 * because Dukavo returns a bearer token rather than Fortify's session payload.
 *
 * The secret and the recovery-code hashes are both encrypted by the model cast.
 * Recovery codes are additionally hashed one-way, so even after decryption the
 * stored value cannot be replayed. Nothing in this class writes a secret, a
 * recovery code or a submitted one-time code to the security history.
 */
final class TwoFactorService
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
        private readonly SecurityEventLogger $events,
        private readonly AccountEmailService $emails,
    ) {}

    /**
     * @return array{enabled: bool, pending: bool, confirmed_at: string|null, recovery_codes_count: int}
     */
    public function status(User $user): array
    {
        $codes = $user->two_factor_recovery_codes;

        return [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => ! $user->hasTwoFactorEnabled() && is_string($user->two_factor_secret) && $user->two_factor_secret !== '',
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_count' => is_array($codes) ? count($codes) : 0,
        ];
    }

    /**
     * Start (or restart) enrollment. The secret is stored encrypted but remains
     * unconfirmed until a valid code proves the authenticator was set up.
     *
     * @return array{secret: string, provisioning_uri: string, qr_svg: string, issuer: string, account: string}
     */
    public function setup(User $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw BusinessRuleViolation::because('Two-factor authentication is already enabled.');
        }

        $secret = (string) $this->provider->generateSecretKey();
        $issuer = (string) config('dukavo.two_factor.issuer', config('dukavo.name', 'Dukavo'));

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $uri = (string) $this->provider->qrCodeUrl($issuer, $user->email, $secret);

        return [
            'secret' => $secret,
            'provisioning_uri' => $uri,
            'qr_svg' => $this->qrDataUri($uri),
            'issuer' => $issuer,
            'account' => $user->email,
        ];
    }

    /**
     * Confirm enrollment and return the one and only plaintext view of the
     * recovery codes.
     *
     * @return array{codes: list<string>, confirmed_at: string}
     */
    public function confirm(User $user, string $code): array
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            throw BusinessRuleViolation::because('Start two-factor setup before confirming a code.');
        }

        if ($user->hasTwoFactorEnabled()) {
            throw BusinessRuleViolation::because('Two-factor authentication is already enabled.');
        }

        if (! $this->provider->verify($secret, $this->normaliseCode($code))) {
            $this->events->record($user, SecurityEventType::TwoFactorChallengeFailed, SecurityEventStatus::Failure, [
                'context' => 'setup',
            ]);

            throw ValidationException::withMessages([
                'code' => 'The authentication code is invalid.',
            ]);
        }

        $codes = $this->makeRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->hashCodes($codes),
        ])->save();

        $this->events->record($user, SecurityEventType::TwoFactorEnabled);
        $this->emails->send(user: $user, template: 'two_factor_enabled', actionUrl: '/settings/security');

        return [
            'codes' => $codes,
            'confirmed_at' => (string) $user->two_factor_confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * Replace all recovery codes. A valid current TOTP is required, so a stolen
     * session alone cannot mint itself a new set.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        $this->requireEnabled($user);
        $this->requireValidTotp($user, $code, 'regenerate');

        $codes = $this->makeRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $this->hashCodes($codes)])->save();

        $this->events->record($user, SecurityEventType::RecoveryCodesRegenerated);
        $this->emails->send(user: $user, template: 'recovery_codes_regenerated', actionUrl: '/settings/security');

        return $codes;
    }

    /**
     * Disable two-factor authentication after proving both the password and a
     * current factor. Losing the authenticator is handled by a recovery code.
     */
    public function disable(User $user, string $password, ?string $code, ?string $recoveryCode): void
    {
        $this->requireEnabled($user);

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The current password is incorrect.',
            ]);
        }

        $verified = ($code !== null && $code !== '' && $this->verifyTotp($user, $code))
            || ($recoveryCode !== null && $recoveryCode !== '' && $this->consumeRecoveryCode($user, $recoveryCode));

        if (! $verified) {
            $this->events->record($user, SecurityEventType::TwoFactorChallengeFailed, SecurityEventStatus::Failure, [
                'context' => 'disable',
            ]);

            throw ValidationException::withMessages([
                'code' => 'Provide a valid authentication code or recovery code.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->events->record($user, SecurityEventType::TwoFactorDisabled);
        $this->emails->send(user: $user, template: 'two_factor_disabled', actionUrl: '/settings/security');
    }

    /**
     * Begin a login challenge. Only the hash of the random token is stored.
     *
     * @return array{challenge_token: string, expires_at: string, methods: list<string>}
     */
    public function createChallenge(User $user, Request $request): array
    {
        $ttl = max(1, (int) config('dukavo.two_factor.challenge_ttl_minutes', 5));
        $token = Str::random(64);

        // Keep the table small without a separate cleanup job.
        TwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '<', now()->subHour())
            ->delete();

        $challenge = TwoFactorChallenge::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttl),
            'attempts' => 0,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return [
            'challenge_token' => $token,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'methods' => ['totp', 'recovery_code'],
        ];
    }

    /**
     * Exchange a challenge for the user it belongs to. Single-use, time-bound
     * and attempt-limited; a failed attempt never reveals whether the account or
     * the code was the wrong part.
     *
     * @return array{user: User, method: string}
     */
    public function verifyChallenge(string $challengeToken, ?string $code, ?string $recoveryCode, Request $request): array
    {
        $challenge = TwoFactorChallenge::query()
            ->where('token_hash', hash('sha256', $challengeToken))
            ->first();

        if ($challenge === null || ! $challenge->isUsable()) {
            throw ValidationException::withMessages([
                'challenge_token' => 'This sign-in attempt has expired. Sign in again.',
            ]);
        }

        $maxAttempts = max(1, (int) config('dukavo.two_factor.max_attempts', 5));

        if ($challenge->attempts >= $maxAttempts) {
            $challenge->forceFill(['consumed_at' => now()])->save();

            throw ValidationException::withMessages([
                'challenge_token' => 'This sign-in attempt has been locked. Sign in again.',
            ]);
        }

        /** @var User $user */
        $user = $challenge->user;
        $method = null;

        if ($code !== null && $code !== '' && $this->verifyTotp($user, $code)) {
            $method = 'totp';
        } elseif ($recoveryCode !== null && $recoveryCode !== '' && $this->consumeRecoveryCode($user, $recoveryCode)) {
            $method = 'recovery_code';
        }

        if ($method === null) {
            $attempts = $challenge->attempts + 1;
            $challenge->forceFill([
                'attempts' => $attempts,
                'consumed_at' => $attempts >= $maxAttempts ? now() : null,
            ])->save();

            $this->events->record($user, SecurityEventType::TwoFactorChallengeFailed, SecurityEventStatus::Failure, [
                'attempts' => $attempts,
            ], $request);

            throw ValidationException::withMessages([
                'code' => 'The authentication code is invalid.',
            ]);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        return ['user' => $user, 'method' => $method];
    }

    private function requireEnabled(User $user): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw BusinessRuleViolation::because('Two-factor authentication is not enabled.');
        }
    }

    private function requireValidTotp(User $user, string $code, string $context): void
    {
        if (! $this->verifyTotp($user, $code)) {
            $this->events->record($user, SecurityEventType::TwoFactorChallengeFailed, SecurityEventStatus::Failure, [
                'context' => $context,
            ]);

            throw ValidationException::withMessages([
                'code' => 'The authentication code is invalid.',
            ]);
        }
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return $this->provider->verify($secret, $this->normaliseCode($code));
    }

    /**
     * Consume a recovery code, so a code that has been used once can never
     * authenticate again. Each stored value is a one-way hash.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes;

        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        $candidate = $this->normaliseRecoveryCode($code);
        $remaining = [];
        $matched = false;

        foreach ($hashes as $hash) {
            if (! $matched && is_string($hash) && Hash::check($candidate, $hash)) {
                $matched = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if (! $matched) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    private function makeRecoveryCodes(): array
    {
        $count = max(1, min(20, (int) config('dukavo.two_factor.recovery_codes', 8)));
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => Hash::make($code), $codes);
    }

    private function normaliseCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }

    private function normaliseRecoveryCode(string $code): string
    {
        return Str::upper(trim(preg_replace('/\s+/', '', $code) ?? ''));
    }

    private function qrDataUri(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(280, 1),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
