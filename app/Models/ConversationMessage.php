<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message inside a conversation.
 *
 * `direction` records who produced it and `user_id` is only set for outbound
 * messages written by a Dukavo user — inbound messages are attributed to the
 * customer. System messages are generated server-side and never accepted from
 * a client payload.
 */
#[Fillable(['tenant_id', 'conversation_id', 'user_id', 'direction', 'type', 'body', 'external_id', 'meta', 'delivery_status', 'delivered_at', 'read_at', 'sent_at'])]
class ConversationMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'meta' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    /**
     * Human label for the raw `delivery_status` column.
     *
     * The column is a plain string rather than an enum because the provider's own
     * vocabulary is what lands there, and an unknown value must not stop a thread
     * from rendering.
     */
    public function delivery_status_label(): string
    {
        return match ($this->delivery_status) {
            'sent' => 'Sent',
            'delivered' => 'Delivered',
            'read' => 'Read',
            'failed' => 'Failed',
            'pending' => 'Pending',
            default => ucfirst((string) $this->delivery_status),
        };
    }
}
