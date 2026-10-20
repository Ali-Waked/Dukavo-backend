<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Http\Requests\ApiRequest;
use App\Support\PermissionRegistry;
use Illuminate\Validation\Rule;

/**
 * Updating a role.
 *
 * Every field is optional so a rename does not resend the permission set. The
 * service refuses changes to a system role's name and permissions outright, and
 * `slug`, `scope`, `tenant_id` and `is_system` are not accepted at all: scope is
 * an escalation boundary, not an editable field — letting a merchant flip a
 * workspace role to `platform` would hand it Dukavo's own powers.
 */
class UpdateRoleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::slugs())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => 'Unknown permission.',
        ];
    }
}
