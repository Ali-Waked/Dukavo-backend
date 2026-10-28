<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Enums\ConversationStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class IndexConversationsRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['search', 'status', 'channel', 'store_id'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::enum(ConversationStatus::class)],
            'channel' => ['sometimes', 'nullable', 'string', 'max:30'],
            'store_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'mine' => ['sometimes', 'boolean'],
            'unassigned' => ['sometimes', 'boolean'],
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
