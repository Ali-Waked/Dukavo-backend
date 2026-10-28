<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Http\Requests\ApiRequest;

/**
 * An outbound message.
 *
 * `idempotency_key` is the caller's send-once key. The provider's own message id
 * is learned from the response and stored separately, so a receipt always maps
 * to the message Meta created.
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
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:190'],
            // Kept for older clients that called the send-once key external_id.
            'external_id' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}
