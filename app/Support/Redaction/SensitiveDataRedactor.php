<?php

declare(strict_types=1);

namespace App\Support\Redaction;

/**
 * Removes credentials and other material that must never be persisted in a
 * proposal, an error message or an audit snapshot.
 */
final class SensitiveDataRedactor
{
    /**
     * Keys whose values are replaced wholesale. Matching is case-insensitive and
     * ignores separators, so `access_token`, `accessToken` and `ACCESS-TOKEN`
     * all match.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'refresh_token',
        'auth_token',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'private_key',
        'authorization',
        'cookie',
        'credential',
        'credentials',
        'card_number',
        'cvv',
        'cvc',
        'security_code',
        'otp',
    ];

    public const REPLACEMENT = '[redacted]';

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $clean[$key] = self::REPLACEMENT;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_object($value) && ! $value instanceof \DateTimeInterface => self::REPLACEMENT,
                default => $value,
            };
        }

        return $clean;
    }

    public function redactString(string $value): string
    {
        // Error messages occasionally echo a bearer token or an API key. Keep
        // the message useful but never the secret.
        return (string) preg_replace(
            [
                '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i',
                '/\b(api[_-]?key|token|secret|password)\s*[:=]\s*[^\s,;]+/i',
            ],
            '$1 [redacted]',
            $value,
        );
    }

    private function isSensitive(string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            $needle = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $sensitive));

            if ($normalised === $needle || str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }
}
