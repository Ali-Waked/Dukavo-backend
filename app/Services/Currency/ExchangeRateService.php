<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Exceptions\ExchangeRateUnavailable;
use App\Services\Currency\Contracts\ExchangeRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Caches and validates exchange rates fetched by the configured provider.
 *
 * A rate is reused for `ttl_minutes`. Past that it is refreshed; if the provider
 * is down, a cached quote may still be used until `stale_after_minutes`, and
 * beyond that the operation is refused. The age is measured from the provider's
 * own update timestamp, not from when this process cached it, so a provider
 * serving an old quote cannot masquerade as a fresh one.
 */
class ExchangeRateService
{
    public function __construct(
        private readonly ExchangeRateProvider $provider,
        private readonly CacheRepository $cache,
        private readonly CurrencyCatalog $currencies,
    ) {}

    public function rate(string $from, string $to): ExchangeRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if (! $this->currencies->supports($from) || ! $this->currencies->supports($to)) {
            throw new ExchangeRateUnavailable('One of the requested currencies is not supported.', [
                'from' => $from,
                'to' => $to,
            ]);
        }

        if ($from === $to) {
            return new ExchangeRate($from, $to, 1.0, 'identity', CarbonImmutable::now());
        }

        $key = "currency-rate:{$from}:{$to}";
        $cached = $this->cache->get($key);
        $staleAfter = max(1, (int) config('dukavo.exchange_rates.stale_after_minutes', 1440));
        $ttl = max(1, (int) config('dukavo.exchange_rates.ttl_minutes', 360));

        if (is_array($cached)) {
            $rate = $this->hydrate($cached, $from, $to);

            if (! $rate->isStale($ttl)) {
                return $rate;
            }
        }

        try {
            $fresh = $this->provider->fetch($from, $to);
        } catch (ExchangeRateUnavailable $exception) {
            // A cached quote inside the stale window is still usable; anything
            // older is refused rather than silently applied.
            if (isset($rate) && ! $rate->isStale($staleAfter)) {
                return $rate;
            }

            throw $exception;
        }

        if ($fresh->isStale($staleAfter)) {
            throw new ExchangeRateUnavailable('The exchange-rate provider returned an outdated quote.', [
                'from' => $from,
                'to' => $to,
                'fetched_at' => $fresh->fetchedAt->toIso8601String(),
            ]);
        }

        $this->cache->put($key, [
            'from' => $fresh->from,
            'to' => $fresh->to,
            'rate' => $fresh->rate,
            'source' => $fresh->source,
            'fetched_at' => $fresh->fetchedAt->toIso8601String(),
        ], now()->addMinutes($staleAfter));

        return $fresh;
    }

    /**
     * Convert an amount with a known rate, rounded to the target's minor unit.
     */
    public function convert(float $amount, ExchangeRate $rate): float
    {
        return $this->currencies->round($amount * $rate->rate, $rate->to);
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function hydrate(array $cached, string $from, string $to): ExchangeRate
    {
        return new ExchangeRate(
            from: (string) ($cached['from'] ?? $from),
            to: (string) ($cached['to'] ?? $to),
            rate: (float) ($cached['rate'] ?? 0),
            source: (string) ($cached['source'] ?? 'cache'),
            fetchedAt: CarbonImmutable::parse((string) ($cached['fetched_at'] ?? 'now')),
        );
    }
}
