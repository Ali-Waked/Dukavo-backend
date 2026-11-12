<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventStatus;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Auth\DeviceName;
use App\Support\Http\RequestCountry;
use Illuminate\Http\Request;

/**
 * Writes the append-only account security history.
 *
 * This logger is the only writer. It deliberately strips anything that could be
 * replayed: passwords, current-password fields, bearer tokens, cookies, TOTP
 * secrets, recovery codes and raw one-time codes. A metadata payload is filtered
 * recursively and flattened to scalars, so a caller passing a whole request
 * cannot accidentally persist a credential.
 */
final class SecurityEventLogger
{
    /** Keys that must never be written, at any depth. */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'token_hash',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'secret',
        'two_factor_secret',
        'recovery_codes',
        'recovery_code',
        'code',
        'otp',
        'challenge_token',
    ];

    private const MAX_STRING_LENGTH = 500;

    public function __construct(private readonly RequestCountry $requestCountry) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User|int|null $user,
        SecurityEventType $type,
        SecurityEventStatus $status = SecurityEventStatus::Success,
        array $metadata = [],
        ?Request $request = null,
    ): SecurityEvent {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        $request ??= request();

        return SecurityEvent::query()->create([
            'user_id' => $userId,
            'event' => $type,
            'status' => $status,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
            'device' => $request === null ? null : DeviceName::for($request),
            'country_code' => $request === null ? null : $this->requestCountry->from($request),
            'metadata' => $this->sanitize($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * Remove rows older than the retention window. Called by the scheduled
     * command rather than on a request path.
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return SecurityEvent::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata, int $depth = 0): array
    {
        if ($depth >= 4) {
            return [];
        }

        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalised = strtolower((string) $key);

            if (in_array($normalised, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value, $depth + 1);

                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;

                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $clean[$key] = mb_substr((string) $value, 0, self::MAX_STRING_LENGTH);
            }
        }

        return $clean;
    }
}
