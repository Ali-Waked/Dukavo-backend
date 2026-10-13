<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\UserStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a platform account.
 *
 * Roles are not part of this payload: they are granted and revoked through the
 * dedicated role assignment endpoints, which carry their own guards (platform
 * scope only, and never the last active super administrator).
 */
class UpdatePlatformUserRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:190', 'lowercase', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:10'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ];
    }
}
