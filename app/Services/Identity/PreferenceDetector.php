<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\PreferenceSource;
use App\Enums\ThemePreference;
use App\Models\User;
use App\Services\Currency\CurrencyCatalog;
use Illuminate\Http\Request;

/**
 * Detect the personal preferences a new account should start with.
 *
 * Every result carries where it came from and how sure the platform is. A value
 * derived from an IP header is explicitly an estimate; a value that came from
 * the browser's language header is a hint; a value the user saved earlier is the
 * only one treated as authoritative. The onboarding screen shows those labels
 * so nobody is told an approximated city is their verified address.
 */
final class PreferenceDetector
{
    public function __construct(
        private readonly GeoLocationResolver $geo,
        private readonly CountryCatalog $countries,
        private readonly TimezoneCatalog $timezones,
        private readonly CurrencyCatalog $currencies,
    ) {}

    /**
     * @return array<string, array{value: string|null, source: string, confidence: int}>
     */
    public function detect(User $user, Request $request): array
    {
        $location = $this->geo->resolve($request);
        $country = $this->detectCountry($user, $location);
        $city = $this->detectCity($user, $location, $country['value']);

        return [
            'country' => $country,
            'city' => $city,
            'timezone' => $this->detectTimezone($user, $request),
            'locale' => $this->detectLocale($user, $request),
            'theme' => $this->detectTheme($user),
            'currency' => $this->detectCurrency($user, $country),
            'date_format' => $this->storedOrDefault($user->date_format, (string) config('dukavo.identity.defaults.date_format', 'DD/MM/YYYY')),
            'time_format' => $this->storedOrDefault($user->time_format, (string) config('dukavo.identity.defaults.time_format', '24h')),
        ];
    }

    /**
     * @param  array{country_code: string, city: string|null, confidence: int}|null  $location
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function detectCountry(User $user, ?array $location): array
    {
        if ($this->countries->exists($user->country_code)) {
            return $this->entry(strtoupper((string) $user->country_code), PreferenceSource::Existing, 100);
        }

        if ($location !== null) {
            return $this->entry($location['country_code'], PreferenceSource::Ip, $location['confidence']);
        }

        $fallback = $this->countries->default();

        return $fallback === null
            ? $this->entry(null, PreferenceSource::Default, 0)
            : $this->entry($fallback, PreferenceSource::Default, 20);
    }

    /**
     * A city estimate is only usable when the network country agrees with the
     * country the profile will store. Otherwise "Paris" from a VPN exit node
     * would be attached to a person who selected the UAE.
     *
     * @param  array{country_code: string, city: string|null, confidence: int}|null  $location
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function detectCity(User $user, ?array $location, ?string $country): array
    {
        if (is_string($user->city) && trim($user->city) !== '') {
            return $this->entry($user->city, PreferenceSource::Existing, 100);
        }

        if ($location !== null && $location['city'] !== null && $country === $location['country_code']) {
            return $this->entry($location['city'], PreferenceSource::Ip, (int) $location['confidence']);
        }

        return $this->entry(null, PreferenceSource::Default, 0);
    }

    /**
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function detectTimezone(User $user, Request $request): array
    {
        if ($this->timezones->isValid($user->timezone)) {
            return $this->entry((string) $user->timezone, PreferenceSource::Existing, 100);
        }

        $candidate = trim((string) ($request->header('X-Timezone') ?? $request->header('X-User-Timezone') ?? ''));

        if ($this->timezones->isValid($candidate)) {
            return $this->entry($candidate, PreferenceSource::Device, 95);
        }

        // UTC is a safe operating default, not a detection. Saying so matters
        // because a browser in Riyadh and a server in UTC are not the same fact.
        return $this->entry(TimezoneCatalog::FALLBACK, PreferenceSource::Default, 0);
    }

    /**
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function detectLocale(User $user, Request $request): array
    {
        $supported = (array) config('dukavo.locales.supported', ['en']);

        // `users.locale` is NOT NULL with a column default of `en`, so a fresh
        // account always has a value even though nobody chose it. Treat that
        // stored default as authoritative only once the account has completed
        // onboarding or explicitly saved a language; otherwise an Arabic
        // browser would be told its language was already "saved".
        if ($this->hasStoredPreference($user, 'locale')
            && is_string($user->locale)
            && in_array($user->locale, $supported, true)) {
            return $this->entry($user->locale, PreferenceSource::Existing, 100);
        }

        $header = strtolower(trim((string) $request->header('Accept-Language', '')));
        $first = trim(explode(',', $header)[0] ?? '');
        $language = explode('-', trim(explode(';', $first)[0] ?? ''))[0];

        if ($language !== '' && in_array($language, $supported, true)) {
            return $this->entry($language, PreferenceSource::Browser, 90);
        }

        return $this->entry((string) config('dukavo.locales.default', 'en'), PreferenceSource::Default, 20);
    }

    /**
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function detectTheme(User $user): array
    {
        $theme = $user->theme;

        if ($theme instanceof ThemePreference) {
            return $this->entry($theme->value, PreferenceSource::Existing, 100);
        }

        return $this->entry(
            (string) config('dukavo.identity.defaults.theme', ThemePreference::System->value),
            PreferenceSource::Default,
            0,
        );
    }

    /**
     * @param  array{value: string|null, source: string, confidence: int}  $country
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function detectCurrency(User $user, array $country): array
    {
        // `preferred_currency` also has a column default (`USD`). For a person
        // who has not completed onboarding that default is not a choice, so the
        // suggestion should follow their detected country instead of claiming
        // they already picked US dollars.
        if ($this->hasStoredPreference($user, 'preferred_currency')
            && $this->currencies->supports($user->preferred_currency)) {
            return $this->entry(strtoupper((string) $user->preferred_currency), PreferenceSource::Existing, 100);
        }

        $countryRow = $country['value'] === null ? null : $this->countries->find($country['value']);
        $suggested = $countryRow['currency'] ?? null;

        if (is_string($suggested) && $this->currencies->supports($suggested)) {
            return $this->entry(
                strtoupper($suggested),
                $country['source'] === PreferenceSource::Ip->value ? PreferenceSource::Ip : PreferenceSource::Default,
                $country['source'] === PreferenceSource::Ip->value ? max(30, $country['confidence'] - 10) : 20,
            );
        }

        return $this->entry($this->currencies->default(), PreferenceSource::Default, 0);
    }

    /**
     * Whether a column value represents a real choice rather than the schema's
     * default. A completed onboarding, or an explicit source record, means the
     * value was confirmed; a NULL marker plus no source means the column is
     * still showing its default.
     */
    private function hasStoredPreference(User $user, string $field): bool
    {
        if ($user->preferences_initialized_at !== null) {
            return true;
        }

        $sources = $user->preferences_source ?? [];

        return isset($sources[$field]);
    }

    /**
     * @return array{value: string, source: string, confidence: int}
     */
    private function storedOrDefault(?string $stored, string $default): array
    {
        return is_string($stored) && $stored !== ''
            ? $this->entry($stored, PreferenceSource::Existing, 100)
            : $this->entry($default, PreferenceSource::Default, 0);
    }

    /**
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function entry(?string $value, PreferenceSource $source, int $confidence): array
    {
        return [
            'value' => $value,
            'source' => $source->value,
            'confidence' => max(0, min(100, $confidence)),
        ];
    }
}
