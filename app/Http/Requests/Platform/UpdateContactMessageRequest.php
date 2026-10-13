<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\ContactStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a contact message's status (triage). The body of the message is
 * read-only: replies happen over email, and the row stays a faithful copy of
 * what the visitor wrote.
 */
class UpdateContactMessageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ContactStatus::class)],
        ];
    }
}
