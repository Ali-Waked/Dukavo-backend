<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\SecurityEventType;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the account's own security history.
 *
 * This request never accepts a user id. The controller scopes every query to
 * `$request->user()`, so the only thing a caller can change is which of their
 * own events they are looking at.
 */
class SecurityActivityRequest extends ApiRequest
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
            'event' => ['sometimes', 'string', Rule::in(array_column(SecurityEventType::cases(), 'value'))],
            'status' => ['sometimes', 'string', Rule::in(['success', 'failure'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
