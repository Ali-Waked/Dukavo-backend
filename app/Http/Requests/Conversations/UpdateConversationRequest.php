<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Enums\ConversationStatus;
use App\Http\Requests\ApiRequest;
use App\Models\User;
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
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists(User::class, 'id')],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
        ];
    }
}
