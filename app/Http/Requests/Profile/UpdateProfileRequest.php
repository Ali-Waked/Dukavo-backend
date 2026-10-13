<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiRequest;
use App\Services\Profile\ProfileService;
use Illuminate\Validation\Rule;

/**
 * A user editing their own identity.
 *
 * Email is absent deliberately: changing it is only meaningful with a
 * verification step Dukavo does not implement yet, and accepting a new address
 * without proving ownership would leave every account reachable at an unverified
 * address. Status is absent for the same reason it is absent from
 * {@see ProfileService} — it is an administrative decision.
 */
class UpdateProfileRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'string', Rule::in(config('dukavo.locales.supported'))],
        ];
    }
}
