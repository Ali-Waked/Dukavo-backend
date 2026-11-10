<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

use App\Enums\PaymentAttemptStatus;

/**
 * The provider's answer to a charge request.
 *
 * A charge that returns a redirect URL is not paid. Only a `succeeded` status
 * (normally delivered by a verified webhook or an explicit status poll) may
 * mark the order paid.
 */
final readonly class PaymentChargeResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentAttemptStatus $status,
        public ?string $providerReference,
        public ?string $checkoutUrl,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}
}
