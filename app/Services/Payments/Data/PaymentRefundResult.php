<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

use App\Enums\PaymentRefundStatus;

/**
 * The provider's answer to a refund request.
 */
final readonly class PaymentRefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentRefundStatus $status,
        public ?string $providerReference,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}
}
