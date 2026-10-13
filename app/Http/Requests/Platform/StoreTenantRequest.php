<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * Provisioning a tenant.
 *
 * Currency and locale are validated against real lists (ISO codes) because they
 * end up in money formatting and RTL layout decisions; an unknown code would
 * silently produce `$100.00` for a merchant who expects `١٠٠`.
 */
class StoreTenantRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash'],
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:10'],
            'store_name' => ['nullable', 'string', 'max:120'],
            'settings' => ['nullable', 'array'],
            'owner' => ['nullable', 'array'],
            'owner.name' => ['required_with:owner', 'string', 'max:120'],
            'owner.email' => ['required_with:owner', 'email:rfc', 'max:190', 'lowercase'],
            'owner.phone' => ['nullable', 'string', 'max:32'],
            'owner.password' => ['nullable', 'confirmed'],
            'owner.locale' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'owner.name' => 'owner name',
            'owner.email' => 'owner email',
        ];
    }
}
