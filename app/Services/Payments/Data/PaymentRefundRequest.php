<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

/**
 * A refund request bounded by the original captured charge.
 */
final readonly class PaymentRefundRequest
{
    public function __construct(
        public string $providerChargeReference,
        public string $clientReference,
        public string $amount,
        public string $currency,
        public ?string $reason = null,
    ) {}
}
