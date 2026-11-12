<?php

declare(strict_types=1);

namespace App\Services\Channels;

use Illuminate\Http\Request;

/**
 * Verifies provider webhook signatures.
 *
 * Each provider signs differently, and a mismatch between signing schemes is
 * the classic way to leave a webhook endpoint open. Every method fails closed:
 * a missing secret returns false rather than accepting the delivery.
 */
final class WebhookSignatureVerifier
{
    /**
     * Meta (WhatsApp, Instagram, Messenger) `X-Hub-Signature-256`.
     */
    public function meta(Request $request, ?string $secret): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, substr($header, 7));
    }

    /**
     * Telegram sends the secret token it was registered with verbatim.
     */
    public function telegram(Request $request, ?string $secret): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $provided !== '' && hash_equals($secret, $provided);
    }

    /**
     * X (Twitter) signs the raw body with a base64 HMAC-SHA256.
     */
    public function xTwitter(Request $request, ?string $secret): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = (string) $request->header('x-twitter-webhooks-signature', '');

        if ($provided === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($expected, $provided);
    }

    /**
     * A shared secret header for simple HTTP providers (SMS, email receipts).
     */
    public function sharedSecret(Request $request, ?string $secret, string $header = 'X-Webhook-Secret'): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = (string) $request->header($header, '');

        return $provided !== '' && hash_equals($secret, $provided);
    }

    /**
     * Generic hex HMAC verification, used by gateways that sign with a plain
     * `sha256=<hex>` header.
     */
    public function hexHmac(Request $request, ?string $secret, string $header = 'X-Signature'): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = (string) $request->header($header, '');

        if (str_starts_with($provided, 'sha256=')) {
            $provided = substr($provided, 7);
        }

        if ($provided === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $provided);
    }
}
