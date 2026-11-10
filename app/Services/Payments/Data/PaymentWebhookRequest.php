<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

/**
 * A raw provider callback, exactly as received.
 *
 * The raw body is retained because signatures are computed over bytes, not over
 * a re-encoded array.
 */
final readonly class PaymentWebhookRequest
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $payload,
        public array $headers,
        public string $rawBody,
    ) {}
}
