<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Http\Requests\ApiRequest;

/**
 * An outbound message.
 *
 * `external_id` is a provider-side idempotency hint: when the caller already has
 * one (a queued job retry, for example) it is stored so the same provider id can
 * never produce two rows.
 */
class SendMessageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4096'],
            'external_id' => ['nullable', 'string', 'max:190'],
        ];
    }
}
