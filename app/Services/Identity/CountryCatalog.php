<?php

declare(strict_types=1);

namespace App\Services\Identity;

use InvalidArgumentException;

/**
 * The maintained ISO 3166-1 country catalogue.
 *
 * Country rows are generated from public maintained datasets into
 * `config/countries.php`; nothing in the application hardcodes a country list.
 * `currencies` preserves every currency a country issues, while `currency` is a
 * suggested code chosen only from Dukavo's supported ISO 4217 catalogue. A
 * country with several currencies therefore keeps all of them, and the
 * suggestion is always presented as a suggestion.
 */
final class CountryCatalog
{
    /**
     * @return array<string, array{iso2: string, name_en: string, name_ar: string, native: string, dial_code: string|null, currency: string, currencies: list<string>, flag: string, cities_count: int}>
     */
    public function all(): array
    {
        /** @var array<string, array{iso2: string, name_en: string, name_ar: string, native: string, dial_code: string|null, currency: string, currencies: list<string>, flag: string, cities_count: int}> $countries */
        $countries = (array) config('countries', []);

        return $countries;
    }

    /**
     * @return array{iso2: string, name_en: string, name_ar: string, native: string, dial_code: string|null, currency: string, currencies: list<string>, flag: string, cities_count: int}|null
     */
    public function find(?string $iso2): ?array
    {
        $iso2 = strtoupper(trim((string) $iso2));

        if (strlen($iso2) !== 2) {
            return null;
        }

        return $this->all()[$iso2] ?? null;
    }

    public function exists(?string $iso2): bool
    {
        return $this->find($iso2) !== null;
    }

    public function default(): ?string
    {
        $configured = strtoupper(trim((string) config('dukavo.identity.default_country', '')));

        return $this->exists($configured) ? $configured : null;
    }

    /**
     * The full list shaped for a searchable selector.
     *
     * @return list<array<string, mixed>>
     */
    public function options(string $locale = 'en'): array
    {
        $locale = str_starts_with(strtolower($locale), 'ar') ? 'ar' : 'en';
        $options = [];

        foreach ($this->all() as $country) {
            $options[] = $this->option($country, $locale);
        }

        usort($options, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $options;
    }

    /**
     * @param  array<string, mixed>  $country
     * @return array<string, mixed>
     */
    public function option(array $country, string $locale = 'en'): array
    {
        $arabic = str_starts_with(strtolower($locale), 'ar');

        return [
            'iso2' => $country['iso2'],
            'name' => $arabic ? $country['name_ar'] : $country['name_en'],
            'name_en' => $country['name_en'],
            'name_ar' => $country['name_ar'],
            'native' => $country['native'],
            'dial_code' => $country['dial_code'],
            'currency' => $country['currency'],
            'currencies' => $country['currencies'],
            'flag' => $country['flag'],
            'cities_count' => $country['cities_count'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(?string $iso2, string $locale = 'en'): array
    {
        $country = $this->find($iso2);

        if ($country === null) {
            throw new InvalidArgumentException('Unknown country code.');
        }

        return $this->option($country, $locale);
    }

    /**
     * Resolve a calling code to the countries that use it, so a phone field can
     * show the right flag even when several countries share +1 or +7.
     *
     * @return list<array<string, mixed>>
     */
    public function byDialCode(string $dialCode): array
    {
        $dialCode = trim($dialCode);

        return array_values(array_filter(
            $this->options(),
            static fn (array $country): bool => $country['dial_code'] === $dialCode,
        ));
    }
}
