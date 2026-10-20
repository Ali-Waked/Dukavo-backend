<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Http\Requests\ApiRequest;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Updating a team member.
 *
 * Passwords are never required here: a user changing their own password goes
 * through the Fortify endpoint, which requires the current password.
 *
 * A replacement role is locked to tenant scope: reassigning a platform role
 * would elevate a team member to Dukavo staff from inside the workspace. It is
 * also locked to this workspace, so another tenant's custom role cannot be
 * smuggled in by id.
 */
class UpdateUserRequest extends ApiRequest
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:190', 'lowercase'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'locale' => ['sometimes', 'string', 'max:10'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('role_id')) {
                return;
            }

            $role = Role::query()->find($this->integer('role_id'));

            if ($role !== null && $role->scope !== RoleScope::Tenant) {
                $validator->errors()->add(
                    'role_id',
                    'Only tenant roles can be assigned to workspace team members.',
                );

                return;
            }

            if (
                $role !== null
                && $role->tenant_id !== null
                && $role->tenant_id !== $this->tenants->tenant()?->getKey()
            ) {
                $validator->errors()->add('role_id', 'That role belongs to another workspace.');
            }
        });
    }
}
