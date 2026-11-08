<?php

declare(strict_types=1);

namespace App\Services\Currency;

use InvalidArgumentException;

/**
 * The supported ISO 4217 currencies, read from configuration.
 *
 * Nothing else in the application hardcodes a symbol, a localized name or a
 * minor-unit count: validation, API responses, conversion rounding and the SPA
 * all derive from this one catalogue. A code that is not listed here cannot be
 * selected or persisted, which is what keeps "currency" from drifting into free
 * text.
 *
 * Each entry carries both languages so the same record can answer an English or
 * Arabic request without a second lookup. The `name` and `symbol` fields of
 * {@see self::meta()} are resolved for the requested language; the raw
 * `name_en` / `name_ar` fields stay available for clients that cache both.
 */
class CurrencyCatalog
{
    /**
     * @return array<string, array{name_en: string, name_ar: string, symbol: string, symbol_ar: string, decimals: int}>
     */
    public function all(): array
    {
        /** @var array<string, array{name_en: string, name_ar: string, symbol: string, symbol_ar: string, decimals: int}> $supported */
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
     * Everything the API needs to describe one currency in one language.
     *
     * @return array{
     *     code: string,
     *     name: string,
     *     name_en: string,
     *     name_ar: string,
     *     symbol: string,
     *     symbol_en: string,
     *     symbol_ar: string,
     *     decimals: int,
     *     locale: string
     * }
     */
    public function meta(string $code, ?string $locale = null): array
    {
        $code = strtoupper($code);
        $currency = $this->all()[$code] ?? null;

        if ($currency === null) {
            throw new InvalidArgumentException("Unsupported currency [{$code}].");
        }

        $locale = $this->normaliseLocale($locale);
        $arabic = $locale === 'ar';

        return [
            'code' => $code,
            'name' => $arabic ? $currency['name_ar'] : $currency['name_en'],
            'name_en' => $currency['name_en'],
            'name_ar' => $currency['name_ar'],
            'symbol' => $arabic ? $currency['symbol_ar'] : $currency['symbol'],
            'symbol_en' => $currency['symbol'],
            'symbol_ar' => $currency['symbol_ar'],
            'decimals' => $currency['decimals'],
            'locale' => $locale,
        ];
    }

    public function decimals(string $code): int
    {
        return $this->meta($code)['decimals'];
    }

    public function nameFor(string $code, ?string $locale = null): string
    {
        return $this->meta($code, $locale)['name'];
    }

    public function symbolFor(string $code, ?string $locale = null): string
    {
        return $this->meta($code, $locale)['symbol'];
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
     * @return list<array{
     *     code: string,
     *     name: string,
     *     name_en: string,
     *     name_ar: string,
     *     symbol: string,
     *     symbol_en: string,
     *     symbol_ar: string,
     *     decimals: int,
     *     locale: string
     * }>
     */
    public function options(?string $locale = null): array
    {
        $locale = $this->normaliseLocale($locale);
        $options = [];

        foreach (array_keys($this->all()) as $code) {
            $options[] = $this->meta($code, $locale);
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $options;
    }

    /**
     * Only the platform's two published languages are meaningful here; anything
     * else degrades to English rather than returning an empty name.
     */
    private function normaliseLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return str_starts_with($locale, 'ar') ? 'ar' : 'en';
    }
}
