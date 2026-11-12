<?php

declare(strict_types=1);

namespace App\Http\Requests\Stores;

use App\Enums\StoreStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a store.
 */
class StoreStoreRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->prepareSlug();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', $this->tenantUnique('stores', 'slug')],
            'status' => ['sometimes', Rule::enum(StoreStatus::class)],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2', 'uppercase', Rule::exists('payment_countries', 'code')],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'timezone' => ['nullable', 'timezone'],
            'enabled_locales' => ['sometimes', 'nullable', 'array', 'max:25'],
            'enabled_locales.*' => ['required', 'string', Rule::in((array) config('dukavo.locales.supported', ['en']))],
            'default_locale' => ['sometimes', 'nullable', 'string', Rule::in((array) config('dukavo.locales.supported', ['en']))],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $default = $this->input('default_locale');

            if (! is_string($default) || $default === '') {
                return;
            }

            $enabled = $this->input('enabled_locales');
            $enabled = is_array($enabled) && $enabled !== []
                ? $enabled
                : (array) config('dukavo.locales.enabled_default', ['en', 'ar']);

            if (! in_array($default, $enabled, true)) {
                $validator->errors()->add('default_locale', 'The default language must be one of the enabled languages.');
            }
        });
    }
}
