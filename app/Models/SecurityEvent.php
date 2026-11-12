<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecurityEventStatus;
use App\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable account-security fact.
 *
 * There is intentionally no `updated_at`: the history is append-only, and the
 * API exposes a read-only projection of these rows. The logger is the only
 * writer and filters secrets before they reach `metadata`.
 */
#[Fillable(['user_id', 'event', 'status', 'ip_address', 'user_agent', 'device', 'country_code', 'metadata', 'created_at'])]
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event' => SecurityEventType::class,
            'status' => SecurityEventStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
