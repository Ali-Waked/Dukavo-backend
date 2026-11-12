<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiErrorCategory;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded AI call. Append-only: rows are never updated or read back into a
 * response, they only feed usage limits and (later) cost reporting.
 *
 * Deliberately NOT tenant-scoped through {@see BelongsToTenant}:
 * platform-level calls have no current tenant, so a global tenant scope would
 * make them invisible instead of attributed.
 */
#[Fillable([
    'tenant_id',
    'user_id',
    'feature',
    'task',
    'correlation_id',
    'provider',
    'status',
    'error_category',
    'model',
    'input_tokens',
    'output_tokens',
    'total_tokens',
    'fallback_attempts',
    'estimated_cost',
    'cache_hit',
    'latency_ms',
    'provider_response_id',
    'metadata',
    'created_at',
])]
class AiUsage extends Model
{
    /**
     * The table name is singular to match `config('ai.usage.table')`, which the
     * recorder and guards read from.
     */
    protected $table = 'ai_usage';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'fallback_attempts' => 'integer',
            'cache_hit' => 'boolean',
            'error_category' => AiErrorCategory::class,
            'estimated_cost' => 'decimal:6',
            'latency_ms' => 'integer',
        ];
    }

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
}
