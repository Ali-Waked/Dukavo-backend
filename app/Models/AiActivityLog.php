<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiErrorCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider attempt, recorded for the activity log.
 *
 * Every attempt a logical request makes — including the ones a fallback
 * abandoned — is a row, tied together by the correlation id. The table holds
 * metadata only: which provider, which model, how long, what category of
 * failure. Prompts, answers and credentials must never be written here.
 */
#[Fillable([
    'correlation_id',
    'tenant_id',
    'user_id',
    'task',
    'feature',
    'provider',
    'model',
    'status',
    'error_category',
    'http_status',
    'attempt',
    'fallback_attempts',
    'duration_ms',
    'input_tokens',
    'output_tokens',
    'cache_hit',
    'metadata',
    'created_at',
])]
class AiActivityLog extends Model
{
    protected $table = 'ai_activity_logs';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'error_category' => AiErrorCategory::class,
            'http_status' => 'integer',
            'attempt' => 'integer',
            'fallback_attempts' => 'integer',
            'duration_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_hit' => 'boolean',
            'metadata' => 'array',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
