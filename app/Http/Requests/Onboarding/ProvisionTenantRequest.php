<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Requests\ApiRequest;

/**
 * The tenant + first store created right after registration.
 *
 * Only the tenant name is mandatory: everything else falls back to sensible
 * defaults (`slug` derived from the name, `contact_email` to the registered
 * user's address) so the onboarding flow can be a single short form. Nothing
 * here identifies a "role" or "owner" — the caller already *is* the owner; the
 * service pins that down, and a request can never place itself in a role.
 */
class ProvisionTenantRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', 'unique:tenants,slug'],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:10'],
            'store_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'store_name' => 'store name',
            'contact_email' => 'contact email',
        ];
    }

    protected function prepareForValidation(): void
    {
        $strip = static fn (mixed $value): ?string => $value === null
            ? null
            : trim(str_replace(["\r", "\n"], ' ', (string) $value));

        $this->merge([
            'name' => $this->has('name') ? $strip($this->input('name')) : null,
            'store_name' => $this->filled('store_name') ? $strip($this->input('store_name')) : null,
            'contact_email' => $this->filled('contact_email')
                ? mb_strtolower(trim((string) $this->input('contact_email')))
                : null,
        ]);
    }
}
