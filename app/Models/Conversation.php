<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer conversation on any channel.
 *
 * The model is deliberately channel agnostic: `channel` plus `external_id`
 * identifies the thread on the provider side, so adding Instagram or Telegram
 * later is a configuration change, not a schema change.
 */
#[Fillable(['tenant_id', 'store_id', 'customer_id', 'assigned_to', 'channel', 'channel_account', 'external_id', 'subject', 'status', 'whatsapp_account_id', 'display_name', 'phone', 'meta', 'closed_at', 'last_message_at', 'last_message_id'])]
class Conversation extends Model
{
    use BelongsToTenant, SoftDeletes;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'assigned_to' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /**
     * The most recent message, used to preview a thread in the inbox list
     * without loading the whole history.
     *
     * @return HasOne<ConversationMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany('sent_at');
    }

    public function isOpen(): bool
    {
        return $this->status !== ConversationStatus::Closed;
    }

    /**
     * @return Builder<self>
     */
    #[Scope]
    protected function withStatus(Builder $query, ConversationStatus|string $status): void
    {
        $query->where('status', $status instanceof ConversationStatus ? $status->value : $status);
    }

    /**
     * Conversations requiring a reply, newest activity first.
     *
     * @return Builder<self>
     */
    #[Scope]
    protected function needingAttention(Builder $query): void
    {
        $query->withStatus(ConversationStatus::Open)
            ->orderByDesc('last_message_at');
    }
}
