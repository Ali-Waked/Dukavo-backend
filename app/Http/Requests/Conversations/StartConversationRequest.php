<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Http\Requests\ApiRequest;
use App\Models\Customer;
use Illuminate\Validation\Rule;

/**
 * Opening a conversation with a customer.
 *
 * The channel account is an opaque identity as seen by the provider (a WhatsApp
 * phone number, for example). It is the key the inbound webhook will match on,
 * so it is required whenever a channel is named.
 */
class StartConversationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists(Customer::class, 'id')],
            'channel' => ['required', 'string', Rule::in(['whatsapp'])],
            'channel_account' => ['required', 'string', 'max:190'],
            'direction' => ['sometimes', Rule::enum(MessageDirection::class)],
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'body' => ['nullable', 'string', 'max:4096'],
            'external_id' => ['nullable', 'string', 'max:190'],
        ];
    }
}
