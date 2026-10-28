<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Enums\ConversationStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a conversation: reassigning it, changing its priority, or moving it
 * through its status. Only fields that exist in the request are applied, so a
 * status change never overwrites an assignment sent in the same payload.
 */
class UpdateConversationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
        ];
    }
}
