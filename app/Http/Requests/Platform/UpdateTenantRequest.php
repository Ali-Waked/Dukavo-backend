<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\TenantStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Updating a tenant.
 *
 * The slug is stable once created: it appears in the `X-Tenant` header and in
 * support material, and changing it would break every bookmarked link.
 */
class UpdateTenantRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
