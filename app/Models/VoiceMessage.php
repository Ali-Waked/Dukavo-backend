<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VoiceMessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The transcription and reviewed order draft for one inbound voice note.
 *
 * This is derived state: the conversation message remains the source of truth
 * for the attachment, and the cart remains the source of truth for anything that
 * will become an order. Extraction never changes stock or creates an order.
 */
#[Fillable([
    'tenant_id', 'conversation_id', 'conversation_message_id', 'status',
    'media_path', 'media_mime_type', 'media_size', 'media_filename', 'media_purged_at',
    'transcript', 'language', 'provider', 'model', 'transcription_latency_ms', 'confidence',
    'extraction', 'failure_code', 'failure_message', 'attempt_count', 'processed_at',
    'applied_cart_id', 'applied_by', 'applied_at',
])]
class VoiceMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => VoiceMessageStatus::class,
            'extraction' => 'array',
            'confidence' => 'decimal:4',
            'media_size' => 'integer',
            'transcription_latency_ms' => 'integer',
            'attempt_count' => 'integer',
            'media_purged_at' => 'datetime',
            'processed_at' => 'datetime',
            'applied_at' => 'datetime',
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
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function appliedCart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'applied_cart_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function isApplied(): bool
    {
        return $this->applied_cart_id !== null;
    }

    public function mediaAvailable(): bool
    {
        return $this->media_path !== null && $this->media_purged_at === null;
    }
}
