<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hop of a task's provider chain.
 *
 * The first route by priority is the task's primary provider; the rest are
 * fallbacks in listed order. A `tenant_id` makes the route a merchant
 * override, honoured only when BYOK is enabled for that tenant.
 */
#[Fillable([
    'task_id',
    'provider_id',
    'tenant_id',
    'model',
    'priority',
    'is_fallback',
    'max_output_tokens',
    'temperature',
    'timeout_seconds',
    'max_retries',
])]
class AiTaskRoute extends Model
{
    protected $table = 'ai_task_routes';

    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class);
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'priority' => 'integer',
            'is_fallback' => 'boolean',
            'max_output_tokens' => 'integer',
            'temperature' => 'decimal:2',
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
        ];
    }
}
