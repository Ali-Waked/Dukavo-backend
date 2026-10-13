<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Http\Requests\ApiRequest;
use App\Support\PermissionRegistry;
use Illuminate\Validation\Rule;

/**
 * Replacing a role's permission set.
 *
 * `present` rather than `required`: a PUT that omits the list is a mistake,
 * while a PUT of an empty list is the deliberate way to clear a custom role —
 * which `required` would reject because Laravel counts an empty array as
 * empty. Slugs are checked against the registry so a typo fails loudly instead
 * of silently granting nothing; the service repeats the check because it is
 * also called outside HTTP.
 */
class SyncRolePermissionsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:100'],
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
