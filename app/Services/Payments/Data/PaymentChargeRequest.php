<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

/**
 * Everything a provider adapter needs to start a hosted checkout.
 *
 * The amount and currency are already server-priced and normalised; an adapter
 * must send them verbatim and may not recalculate them.
 */
final readonly class PaymentChargeRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $orderReference,
        public string $clientReference,
        public string $amount,
        public string $currency,
        public string $methodCode,
        public string $sourceId,
        public string $description,
        public string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public string $redirectUrl,
        public string $webhookUrl,
        public array $metadata = [],
    ) {}
}
