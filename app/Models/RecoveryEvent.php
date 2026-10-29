<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecoveryEventType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable funnel entry for one recovery opportunity.
 */
#[Fillable([
    'tenant_id', 'recovery_opportunity_id', 'user_id', 'conversation_message_id',
    'type', 'note', 'meta',
])]
class RecoveryEvent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'type' => RecoveryEventType::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RecoveryOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(RecoveryOpportunity::class, 'recovery_opportunity_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }
}
