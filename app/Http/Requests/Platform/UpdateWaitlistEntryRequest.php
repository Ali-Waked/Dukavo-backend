<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\WaitlistStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a waitlist entry's status. The status is the only writable field:
 * signups are immutable records of what the visitor submitted, and the email
 * is an identity, not an editable property.
 */
class UpdateWaitlistEntryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(WaitlistStatus::class)],
        ];
    }
}
