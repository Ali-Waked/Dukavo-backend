<?php

declare(strict_types=1);

namespace App\Services\Currency\Providers;

use App\Exceptions\ExchangeRateUnavailable;
use App\Services\Currency\Contracts\ExchangeRateProvider;
use App\Services\Currency\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Fetches rates from an open.er-api.com compatible endpoint.
 *
 * The response shape is the one that provider documents:
 * `{ result: "success", base_code: "USD", time_last_update_unix: 1700000000,
 *    rates: { "EUR": 0.92, ... } }`.
 *
 * Any deviation — non-2xx, `result` other than `success`, a missing rate — is an
 * outage, not a zero. The exception carries the HTTP status for the log while
 * the API returns a generic 503 to the caller.
 */
class OpenExchangeRateProvider implements ExchangeRateProvider
{
    public function fetch(string $from, string $to): ExchangeRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        $baseUrl = rtrim((string) config('dukavo.exchange_rates.base_url'), '/');
        $timeout = max(1, (int) config('dukavo.exchange_rates.timeout_seconds', 8));

        if ($baseUrl === '') {
            throw new ExchangeRateUnavailable('No exchange-rate provider is configured.');
        }

        try {
            $response = Http::timeout($timeout)->acceptJson()->get($baseUrl.'/'.$from);
        } catch (\Throwable $exception) {
            throw new ExchangeRateUnavailable('The exchange-rate provider could not be reached.', [
                'from' => $from,
                'to' => $to,
                'reason' => $exception->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            throw new ExchangeRateUnavailable('The exchange-rate provider returned an error.', [
                'from' => $from,
                'to' => $to,
                'status' => $response->status(),
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['result'] ?? null) !== 'success') {
            throw new ExchangeRateUnavailable('The exchange-rate provider returned an unexpected response.', [
                'from' => $from,
                'to' => $to,
            ]);
        }

        $rate = $payload['rates'][$to] ?? null;

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new ExchangeRateUnavailable('The requested currency pair is not quoted.', [
                'from' => $from,
                'to' => $to,
            ]);
        }

        $updatedAt = isset($payload['time_last_update_unix'])
            ? CarbonImmutable::createFromTimestampUTC((int) $payload['time_last_update_unix'])
            : CarbonImmutable::now();

        return new ExchangeRate(
            from: $from,
            to: $to,
            rate: (float) $rate,
            source: (string) config('dukavo.exchange_rates.provider', 'open.er-api.com'),
            fetchedAt: $updatedAt,
        );
    }
}
