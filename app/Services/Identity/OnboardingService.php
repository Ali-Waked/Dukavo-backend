<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\PreferenceSource;
use App\Enums\ThemePreference;
use App\Models\User;
use App\Services\Currency\CurrencyCatalog;
use Illuminate\Http\Request;

/**
 * The one-time first-login preference confirmation.
 *
 * Onboarding never blocks access: a user may skip it and still reach every
 * surface. It is also strictly one-shot. `preferences_initialized_at` is the
 * single marker, and the migration backfills it for accounts that already
 * existed, so deploying this feature cannot ambush long-standing users with a
 * "welcome" screen.
 *
 * Detection is advisory. Completing the flow records the user's confirmation;
 * values the user changed are marked manual, values left exactly as detected
 * keep their honest source (an IP estimate stays an IP estimate, never
 * "verified").
 */
final class OnboardingService
{
    /** Fields that may be written through onboarding. */
    private const PREFERENCE_FIELDS = [
        'country_code',
        'city',
        'timezone',
        'locale',
        'theme',
        'preferred_currency',
        'date_format',
        'time_format',
    ];

    public function __construct(
        private readonly PreferenceDetector $detector,
        private readonly CountryCatalog $countries,
        private readonly CurrencyCatalog $currencies,
    ) {}

    /**
     * @return array{required: bool, completed_at: string|null, detected: array<string, mixed>}
     */
    public function status(User $user, Request $request): array
    {
        return [
            'required' => $this->isRequired($user),
            'completed_at' => $user->preferences_initialized_at?->toIso8601String(),
            'detected' => $this->detected($user, $request),
        ];
    }

    public function isRequired(User $user): bool
    {
        return (bool) config('dukavo.identity.onboarding.enabled', true)
            && $user->preferences_initialized_at === null;
    }

    /**
     * The detected values shaped for the onboarding form.
     *
     * @return array<string, mixed>
     */
    public function detected(User $user, Request $request): array
    {
        $detected = $this->detector->detect($user, $request);
        $locale = (string) config('dukavo.locales.default', 'en');

        $countryRow = $detected['country']['value'] === null
            ? null
            : $this->countries->find($detected['country']['value']);

        $currencyCode = (string) ($detected['currency']['value'] ?? $this->currencies->default());
        $currency = $this->currencies->supports($currencyCode)
            ? $this->currencies->meta($currencyCode, $locale)
            : $this->currencies->meta($this->currencies->default(), $locale);

        return [
            'country' => [
                ...$detected['country'],
                'country' => $countryRow === null ? null : $this->countries->option($countryRow, $locale),
            ],
            'city' => $detected['city'],
            'timezone' => $detected['timezone'],
            'locale' => $detected['locale'],
            'theme' => $detected['theme'],
            'currency' => [
                ...$detected['currency'],
                'name' => $currency['name'],
                'symbol' => $currency['symbol'],
                'decimals' => $currency['decimals'],
            ],
            'date_format' => $detected['date_format'],
            'time_format' => $detected['time_format'],
            'phone_country_code' => [
                'value' => $countryRow['iso2'] ?? null,
                'source' => $detected['country']['source'],
                'confidence' => $detected['country']['confidence'],
                'dial_code' => $countryRow['dial_code'] ?? null,
            ],
        ];
    }

    /**
     * Confirm the flow. A second call is a no-op, and nothing already
     * initialized is ever overwritten by a replay.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function complete(User $user, array $attributes, Request $request): User
    {
        if ($user->preferences_initialized_at !== null) {
            return $user;
        }

        $detected = $this->detector->detect($user, $request);
        $sources = $user->preferences_source ?? [];

        if (isset($attributes['name'])) {
            $user->name = (string) $attributes['name'];
        }

        if (array_key_exists('bio', $attributes) && $attributes['bio'] !== null) {
            $user->bio = (string) $attributes['bio'];
        }

        foreach (self::PREFERENCE_FIELDS as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                continue;
            }

            $value = (string) $attributes[$field];

            // Compare against detection so a confirmed value keeps its honest
            // provenance while an edited one is clearly the user's own choice.
            $detectedKey = $field === 'country_code' ? 'country' : ($field === 'preferred_currency' ? 'currency' : $field);
            $wasDetected = (string) ($detected[$detectedKey]['value'] ?? '') === $value;

            $source = $wasDetected
                ? PreferenceSource::from((string) $detected[$detectedKey]['source'])
                : PreferenceSource::Manual;
            $confidence = $wasDetected ? (int) $detected[$detectedKey]['confidence'] : 100;

            $user->{$field} = $value;
            $sources[$field] = ['source' => $source->value, 'confidence' => $confidence];
        }

        if (isset($attributes['phone'])) {
            $user->phone = (string) $attributes['phone'];
        }

        if (isset($attributes['phone_country_code'])) {
            $phoneCountry = strtoupper((string) $attributes['phone_country_code']);
            $user->phone_country_code = $this->countries->exists($phoneCountry) ? $phoneCountry : null;
        } elseif ($user->phone_country_code === null && $user->country_code !== null) {
            $user->phone_country_code = $user->country_code;
        }

        $user->preferences_source = $sources;
        $user->preferences_initialized_at = now();
        $user->save();

        return $user;
    }

    /**
     * Dismiss the flow without changing anything. The account is marked so the
     * prompt never returns; the profile screen remains the place to edit later.
     */
    public function skip(User $user): User
    {
        if ($user->preferences_initialized_at !== null) {
            return $user;
        }

        $user->preferences_initialized_at = now();
        $user->save();

        return $user;
    }

    /**
     * The preferences payload the Vue profile and Flutter settings screens render.
     *
     * @return array<string, mixed>
     */
    public function preferences(User $user, Request $request): array
    {
        $detected = $this->detector->detect($user, $request);
        $sources = $user->preferences_source ?? [];
        $locale = (string) config('dukavo.locales.default', 'en');

        $countryRow = $user->country_code === null ? null : $this->countries->find($user->country_code);
        $currency = $this->currencies->supports($user->preferred_currency)
            ? $this->currencies->meta((string) $user->preferred_currency, $locale)
            : $this->currencies->meta($this->currencies->default(), $locale);

        return [
            'country' => $this->valueWithSource($user->country_code, $sources, 'country_code', $detected['country']),
            'country_meta' => $countryRow === null ? null : $this->countries->option($countryRow, $locale),
            'city' => $this->valueWithSource($user->city, $sources, 'city', $detected['city']),
            'timezone' => $this->valueWithSource($user->timezone, $sources, 'timezone', $detected['timezone']),
            'locale' => $this->valueWithSource($user->locale, $sources, 'locale', $detected['locale']),
            'theme' => $this->valueWithSource(
                $user->theme instanceof ThemePreference ? $user->theme->value : (string) config('dukavo.identity.defaults.theme', 'system'),
                $sources,
                'theme',
                $detected['theme'],
            ),
            'currency' => [
                ...$this->valueWithSource($user->preferred_currency, $sources, 'preferred_currency', $detected['currency']),
                'name' => $currency['name'],
                'symbol' => $currency['symbol'],
                'decimals' => $currency['decimals'],
            ],
            'date_format' => $this->valueWithSource($user->date_format, $sources, 'date_format', $detected['date_format']),
            'time_format' => $this->valueWithSource($user->time_format, $sources, 'time_format', $detected['time_format']),
            'phone_country_code' => $this->valueWithSource(
                $user->phone_country_code,
                $sources,
                'phone_country_code',
                $detected['country'],
            ),
            'initialized_at' => $user->preferences_initialized_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sources
     * @param  array{value: string|null, source: string, confidence: int}  $fallback
     * @return array{value: string|null, source: string, confidence: int}
     */
    private function valueWithSource(?string $value, array $sources, string $field, array $fallback): array
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $source = $sources[$field] ?? null;

        return [
            'value' => $value,
            'source' => is_array($source) ? (string) ($source['source'] ?? PreferenceSource::Existing->value) : PreferenceSource::Existing->value,
            'confidence' => is_array($source) ? (int) ($source['confidence'] ?? 100) : 100,
        ];
    }
}
