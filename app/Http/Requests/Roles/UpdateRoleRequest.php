<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Enums\RoleScope;
use App\Http\Requests\ApiRequest;
use App\Support\PermissionRegistry;
use Illuminate\Validation\Rule;

/**
 * Updating a role.
 *
 * Every field is optional so a rename does not resend the permission set. The
 * service refuses changes to a system role's slug, scope and permissions, and
 * `is_system` and `slug` are not accepted at all.
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
            'scope' => ['sometimes', Rule::enum(RoleScope::class)],
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
