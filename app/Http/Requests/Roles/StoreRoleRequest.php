<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Enums\RoleScope;
use App\Http\Requests\ApiRequest;
use App\Services\Rbac\PermissionService;
use App\Support\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a role.
 *
 * The permission list is validated against the registry rather than the
 * database, so a typo fails loudly instead of silently granting nothing. Scope
 * is validated against the *caller*, never against the payload: only a platform
 * administrator can mint a platform role. A tenant role must be created inside
 * a workspace, so it is owned by that tenant rather than left global.
 */
class StoreRoleRequest extends ApiRequest
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $tenants,
    ) {}

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
            $scope = $this->input('scope');

            // A tenant role must have a tenant to belong to; minting one with no
            // workspace would make it global, i.e. shared by every merchant.
            if ($scope === RoleScope::Tenant->value && $this->tenants->tenant() === null) {
                $validator->errors()->add('scope', 'Tenant roles must be created inside a workspace.');

                return;
            }

            if ($scope !== RoleScope::Platform->value) {
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
