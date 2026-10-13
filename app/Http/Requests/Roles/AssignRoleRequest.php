<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Http\Requests\ApiRequest;
use App\Services\Rbac\RoleService;
use Illuminate\Validation\Rule;

/**
 * Identifying the user a role assignment action applies to.
 *
 * Only the shape is validated here. Whether the user may hold the role — a
 * member of the active tenant, the right scope for the assignment — is a
 * business rule enforced by {@see RoleService::assign()},
 * which is also the path non-HTTP callers use.
 */
class AssignRoleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
