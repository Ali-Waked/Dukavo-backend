<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

/**
 * The result of a provider-side credential check.
 */
final readonly class PaymentConnectionResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public array $raw = [],
    ) {}
}
