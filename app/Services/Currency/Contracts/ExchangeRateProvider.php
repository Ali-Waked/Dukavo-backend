<?php

declare(strict_types=1);

namespace App\Services\Currency\Contracts;

use App\Services\Currency\ExchangeRate;

/**
 * The only seam through which Dukavo obtains an exchange rate.
 *
 * Production uses an HTTP provider; tests replace this binding with a fixed
 * quote so a conversion test never depends on a third-party service. A provider
 * must throw when it cannot produce a trustworthy rate — returning a guessed
 * value is never acceptable for a financial operation.
 */
interface ExchangeRateProvider
{
    public function fetch(string $from, string $to): ExchangeRate;
}
