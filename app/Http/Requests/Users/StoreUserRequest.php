<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Http\Requests\ApiRequest;
use App\Models\Role;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Adding a team member.
 *
 * `email` is unique per tenant rather than globally: the same person may be a
 * staff member of one merchant and a customer contact of another, and a global
 * unique index would leak that relationship between tenants.
 *
 * The role is locked to tenant scope: a platform role would make the invitee
 * Dukavo staff, which is a platform concern managed outside the workspace.
 * Ownership is a membership-level privilege, never something a role can confer,
 * so only an owner (or a platform operator) may mint another owner.
 */
class StoreUserRequest extends ApiRequest
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'lowercase'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'locale' => ['sometimes', 'string', 'max:10'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'is_owner' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tenant = $this->tenants->tenant();
            $role = Role::query()->find($this->integer('role_id'));

            if ($role !== null && $role->scope !== RoleScope::Tenant) {
                $validator->errors()->add(
                    'role_id',
                    'Only tenant roles can be assigned to workspace team members.',
                );
            }

            // Another tenant's custom role is not assignable here, even though
            // the id resolves: it is not this workspace's to hand out.
            if (
                $role !== null
                && $role->tenant_id !== null
                && $role->tenant_id !== $tenant?->getKey()
            ) {
                $validator->errors()->add('role_id', 'That role belongs to another workspace.');
            }

            if (! $this->boolean('is_owner')) {
                return;
            }

            $actor = $this->user();

            $isOwner = $actor !== null && $tenant !== null && $actor->tenants()
                ->whereKey($tenant->getKey())
                ->wherePivot('is_owner', true)
                ->exists();

            if (! $isOwner && ($actor === null || ! $this->permissions->isPlatformOperator($actor))) {
                $validator->errors()->add('is_owner', 'Only a workspace owner may add another owner.');
            }
        });
    }
}
