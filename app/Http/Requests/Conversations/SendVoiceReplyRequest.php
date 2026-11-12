<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Http\Requests\ApiRequest;

/**
 * A written reply that will be spoken back as a voice note.
 *
 * The text is the same answer a merchant would type; the voice pipeline turns
 * it into audio and sends it on the conversation's channel. It is never stored
 * as a text message, so there is no duplicate bubble in the thread.
 */
class SendVoiceReplyRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4096'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}
