<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

use App\Enums\PaymentAttemptStatus;

/**
 * The provider's authoritative view of a charge.
 */
final readonly class PaymentStatusResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentAttemptStatus $status,
        public ?string $providerReference,
        public ?string $amount,
        public ?string $currency,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}
}
