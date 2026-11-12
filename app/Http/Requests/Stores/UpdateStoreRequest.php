<?php

declare(strict_types=1);

namespace App\Http\Requests\Stores;

use App\Enums\StoreStatus;
use App\Http\Requests\ApiRequest;
use App\Models\Store;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Updating a store. `is_default` is accepted but the service refuses to unset it;
 * use the dedicated `make-default` action to move the flag.
 */
class UpdateStoreRequest extends ApiRequest
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
        $store = $this->route('store');
        $ignoreId = $store instanceof Store ? $store->getKey() : null;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'alpha_dash', $this->tenantUnique('stores', 'slug', $ignoreId)],
            'status' => ['sometimes', Rule::enum(StoreStatus::class)],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'uppercase', Rule::exists('payment_countries', 'code')],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'timezone' => ['sometimes', 'timezone'],
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

            $store = $this->route('store');
            $enabled = $this->input('enabled_locales');

            if (! is_array($enabled) || $enabled === []) {
                $enabled = $store instanceof Store && is_array($store->enabled_locales) && $store->enabled_locales !== []
                    ? $store->enabled_locales
                    : (array) config('dukavo.locales.enabled_default', ['en', 'ar']);
            }

            if (! in_array($default, $enabled, true)) {
                $validator->errors()->add('default_locale', 'The default language must be one of the enabled languages.');
            }
        });
    }
}
