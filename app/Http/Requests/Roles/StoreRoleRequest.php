<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Enums\RoleScope;
use App\Http\Requests\ApiRequest;
use App\Services\Rbac\PermissionService;
use App\Support\PermissionRegistry;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a role.
 *
 * The permission list is validated against the registry rather than the
 * database, so a typo fails loudly instead of silently granting nothing. Scope
 * is validated against the *caller*, never against the payload: only a platform
 * administrator can mint a platform role.
 */
class StoreRoleRequest extends ApiRequest
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::enum(RoleScope::class)],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::slugs())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('scope') !== RoleScope::Platform->value) {
                return;
            }

            $user = $this->user();

            if ($user === null || ! $this->permissions->isPlatformOperator($user)) {
                $validator->errors()->add('scope', 'Only platform administrators may create platform roles.');
            }
        });
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
