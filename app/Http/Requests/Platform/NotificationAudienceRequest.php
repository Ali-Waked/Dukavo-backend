<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Counting who an audience would reach before publishing.
 *
 * Validating the audience shape here means the operator gets a 422 for a
 * nonsensical target instead of a silent zero-recipient campaign.
 */
class NotificationAudienceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audience' => ['required', 'array'],
            'audience.type' => ['required', 'string', Rule::in(['all', 'tenants', 'users'])],
            'audience.tenant_ids' => ['required_if:audience.type,tenants', 'array', 'max:500'],
            'audience.tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'audience.user_ids' => ['required_if:audience.type,users', 'array', 'max:500'],
            'audience.user_ids.*' => ['integer', 'exists:users,id'],
            'audience.exclude_user_ids' => ['sometimes', 'array', 'max:500'],
            'audience.exclude_user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function audience(): array
    {
        /** @var array<string, mixed> $audience */
        $audience = $this->validated('audience');

        return $audience;
    }
}
