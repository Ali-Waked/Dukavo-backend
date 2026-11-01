<?php

declare(strict_types=1);

namespace App\Services\Currency;

use InvalidArgumentException;

/**
 * The supported ISO 4217 currencies, read from configuration.
 *
 * Nothing else in the application hardcodes a symbol or a minor-unit count:
 * validation, API responses, conversion rounding and the SPA all derive from
 * this one catalogue. A code that is not listed here cannot be selected or
 * persisted, which is what keeps "currency" from drifting into free text.
 */
class CurrencyCatalog
{
    /**
     * @return array<string, array{name: string, symbol: string, decimals: int, locale: string}>
     */
    public function all(): array
    {
        /** @var array<string, array{name: string, symbol: string, decimals: int, locale: string}> $supported */
        $supported = (array) config('dukavo.currencies.supported', []);

        return $supported;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function default(): string
    {
        return strtoupper((string) config('dukavo.currencies.default', 'USD'));
    }

    public function supports(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), $this->all());
    }

    /**
     * @return array{code: string, name: string, symbol: string, decimals: int, locale: string}
     */
    public function meta(string $code): array
    {
        $code = strtoupper($code);
        $currency = $this->all()[$code] ?? null;

        if ($currency === null) {
            throw new InvalidArgumentException("Unsupported currency [{$code}].");
        }

        return ['code' => $code] + $currency;
    }

    public function decimals(string $code): int
    {
        return $this->meta($code)['decimals'];
    }

    /**
     * Round an amount to the target currency's minor unit.
     *
     * `round()` is deliberate: the value is already a decimal string from the
     * database, and the only decision left is how many minor units the target
     * currency can represent.
     */
    public function round(float $amount, string $code): float
    {
        return round($amount, $this->decimals($code));
    }

    /**
     * The full list shaped for the SPA's currency selector.
     *
     * @return list<array{code: string, name: string, symbol: string, decimals: int, locale: string}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $code => $currency) {
            $options[] = ['code' => $code] + $currency;
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $options;
    }
}
