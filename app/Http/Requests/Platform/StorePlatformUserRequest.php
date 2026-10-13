<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\RoleScope;
use App\Http\Requests\ApiRequest;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a Dukavo platform account.
 *
 * A platform user is a staff identity: it holds a platform scoped role and no
 * tenant membership. The role is therefore locked to platform scope here — a
 * merchant role only makes sense attached to a tenant, which this endpoint does
 * not touch.
 */
class StorePlatformUserRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'lowercase', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:12'],
            'locale' => ['nullable', 'string', 'max:10'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = Role::query()->find($this->integer('role_id'));

            if ($role !== null && $role->scope !== RoleScope::Platform) {
                $validator->errors()->add(
                    'role_id',
                    'Only platform roles can be assigned to platform administrators.',
                );
            }
        });
    }
}
