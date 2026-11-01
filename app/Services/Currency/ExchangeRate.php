<?php

declare(strict_types=1);

namespace App\Services\Currency;

use Carbon\CarbonImmutable;

/**
 * A quoted exchange rate and where it came from.
 *
 * `fetchedAt` travels with the rate so a caller can reject a stale quote rather
 * than applying an old price to a live catalogue.
 */
final readonly class ExchangeRate
{
    public function __construct(
        public string $from,
        public string $to,
        public float $rate,
        public string $source,
        public CarbonImmutable $fetchedAt,
    ) {}

    public function ageMinutes(): int
    {
        return max(0, (int) $this->fetchedAt->diffInMinutes(CarbonImmutable::now()));
    }

    public function isStale(int $staleAfterMinutes): bool
    {
        return $this->ageMinutes() > $staleAfterMinutes;
    }
}
