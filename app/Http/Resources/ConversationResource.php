<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Conversation $resource
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'customer_id' => $this->customer_id,
            'assigned_to' => $this->assigned_to,
            'channel' => $this->channel,
            'channel_account' => $this->channel_account,
            'external_id' => $this->external_id,
            'subject' => $this->subject,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'unread_count' => (int) $this->unread_count,
            'last_read_at' => $this->last_read_at?->toIso8601String(),
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'reply_window' => $this->replyWindow(),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'last_message' => $this->whenLoaded('latestMessage', function (): ?array {
                $message = $this->latestMessage;

                if ($message === null) {
                    return null;
                }

                return [
                    'id' => $message->id,
                    'body' => $message->body,
                    'type' => $message->type->value,
                    'direction' => $message->direction->value,
                    'is_internal' => $message->isInternal(),
                    'delivery_status' => $message->delivery_status,
                    'sent_at' => $message->sent_at?->toIso8601String(),
                ];
            }),
            'messages_count' => $this->whenCounted('messages'),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
