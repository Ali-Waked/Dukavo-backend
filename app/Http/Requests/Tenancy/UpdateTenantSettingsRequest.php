<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Workspace settings a merchant may edit about their own tenant.
 *
 * The slug is not accepted: it is the stable identifier embedded in the tenant
 * header and any links the workspace has already shared, so renaming the
 * business must not silently break them. `status` is not accepted either — that
 * is a platform decision (see Platform\TenantController).
 */
class UpdateTenantSettingsRequest extends ApiRequest
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
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', Rule::in(config('dukavo.locales.supported'))],
        ];
    }
}
