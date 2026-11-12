<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\ThemePreference;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * First-login preference confirmation.
 *
 * Every field is optional: the screen may submit only what the person touched,
 * and skipping the form is its own endpoint. The server never trusts a client
 * claim about where a value came from; it re-detects and marks an unchanged
 * value with its honest source.
 */
class CompleteOnboardingRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'phone_country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'uppercase', Rule::in(array_keys((array) config('countries', [])))],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'uppercase', Rule::in(array_keys((array) config('countries', [])))],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in((array) config('timezones', []))],
            'locale' => ['sometimes', 'string', Rule::in(config('dukavo.locales.supported'))],
            'theme' => ['sometimes', 'string', Rule::in(array_column(ThemePreference::cases(), 'value'))],
            'preferred_currency' => ['sometimes', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'date_format' => ['sometimes', 'string', Rule::in((array) config('dukavo.identity.date_formats', []))],
            'time_format' => ['sometimes', 'string', Rule::in((array) config('dukavo.identity.time_formats', []))],
        ];
    }
}
