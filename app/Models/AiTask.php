<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTask as AiTaskEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A routed AI task, e.g. `translation` or `conversation_summary`.
 *
 * The row carries the platform policy for the task: whether it may run at
 * all, its caps and its transport parameters. Routing itself lives in
 * {@see AiTaskRoute}. Limits of `0` mean "no explicit cap" — the legacy
 * feature configuration continues to apply — so a task row alone never
 * tightens an existing deployment.
 *
 * The task *enum* is imported under an alias: this class and the enum share
 * the short name `AiTask`, and the engine's addressing key comes from the
 * enum, never from a free-text field.
 */
#[Fillable([
    'key',
    'label',
    'enabled',
    'daily_per_user',
    'daily_per_tenant',
    'monthly_per_tenant',
    'monthly_platform_requests',
    'max_output_tokens',
    'temperature',
    'timeout_seconds',
    'max_retries',
    'description',
])]
class AiTask extends Model
{
    protected $table = 'ai_tasks';

    /**
     * The enum case this row represents.
     */
    public function task(): AiTaskEnum
    {
        return AiTaskEnum::from($this->key);
    }

    /**
     * @return HasMany<AiTaskRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(AiTaskRoute::class, 'task_id');
    }

    /**
     * @return HasMany<AiPromptTemplate, $this>
     */
    public function promptTemplates(): HasMany
    {
        return $this->hasMany(AiPromptTemplate::class, 'task_id');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_per_user' => 'integer',
            'daily_per_tenant' => 'integer',
            'monthly_per_tenant' => 'integer',
            'monthly_platform_requests' => 'integer',
            'max_output_tokens' => 'integer',
            'temperature' => 'decimal:2',
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
        ];
    }
}
