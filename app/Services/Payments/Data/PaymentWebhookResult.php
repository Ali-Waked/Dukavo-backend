<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

use App\Enums\PaymentAttemptStatus;

/**
 * A provider callback after signature verification and normalisation.
 *
 * `clientReference` is what ties the callback to one Dukavo payment attempt;
 * `providerReference` is the provider's own charge id. A result with a null
 * status is a verified event that does not change payment state.
 */
final readonly class PaymentWebhookResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $clientReference,
        public ?string $providerReference,
        public ?PaymentAttemptStatus $status,
        public ?string $amount,
        public ?string $currency,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}
}
