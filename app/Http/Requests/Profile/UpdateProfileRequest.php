<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\ThemePreference;
use App\Http\Requests\ApiRequest;
use App\Services\Profile\ProfileService;
use Illuminate\Validation\Rule;

/**
 * A user editing their own identity.
 *
 * Email is absent deliberately: changing it is only meaningful with a
 * verification step Dukavo does not implement yet, and accepting a new address
 * without proving ownership would leave every account reachable at an unverified
 * address. Status is absent for the same reason it is absent from
 * {@see ProfileService} — it is an administrative decision.
 */
class UpdateProfileRequest extends ApiRequest
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
            'locale' => ['sometimes', 'string', Rule::in(config('dukavo.locales.supported'))],
            'preferred_currency' => ['sometimes', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'uppercase', Rule::in(array_keys((array) config('countries', [])))],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in((array) config('timezones', []))],
            'theme' => ['sometimes', 'string', Rule::in(array_column(ThemePreference::cases(), 'value'))],
            'date_format' => ['sometimes', 'string', Rule::in((array) config('dukavo.identity.date_formats', []))],
            'time_format' => ['sometimes', 'string', Rule::in((array) config('dukavo.identity.time_formats', []))],
        ];
    }
}
