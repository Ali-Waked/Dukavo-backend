<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The only place a platform secret is encrypted or decrypted.
 *
 * Laravel's encrypter is already authenticated, so a tampered ciphertext fails
 * closed rather than producing a corrupted credential. A decryption failure is
 * reported as "not configured" instead of throwing on a dashboard read: the
 * usual cause is a rotated APP_KEY, and the operator needs the settings page to
 * load so the value can be replaced.
 *
 * The hint is always the last four characters of the plaintext. It exists so an
 * operator can match a value against the provider's own dashboard; it is never
 * enough to reconstruct the secret and is never logged.
 */
final class SecretEncryptionService
{
    public function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    public function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || trim($ciphertext) === '') {
            return null;
        }

        try {
            $plaintext = Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            return null;
        }

        return is_string($plaintext) && $plaintext !== '' ? $plaintext : null;
    }

    /**
     * A non-reversible fingerprint for display: the last four characters.
     */
    public function hint(string $plaintext): ?string
    {
        $plaintext = trim($plaintext);

        if ($plaintext === '') {
            return null;
        }

        return mb_substr($plaintext, -4);
    }
}
