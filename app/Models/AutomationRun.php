<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AutomationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An append-only record of one rule evaluation.
 *
 * `event_key` is unique per rule, which makes execution safe under queue
 * retries and repeated event delivery. Runs are never edited by merchants.
 */
#[Fillable([
    'tenant_id', 'automation_rule_id', 'trigger', 'event_key', 'status',
    'attempts', 'source', 'matched', 'context', 'actions', 'error',
    'duration_ms', 'started_at', 'queued_at', 'finished_at',
])]
class AutomationRun extends Model
{
    /** @use HasFactory<AutomationRunFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'status' => AutomationRunStatus::class,
            'attempts' => 'integer',
            'matched' => 'boolean',
            'context' => 'array',
            'actions' => 'array',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'queued_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return HasMany<AutomationRunLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AutomationRunLog::class)->orderBy('id');
    }

    public function duration(): ?int
    {
        if ($this->duration_ms !== null) {
            return (int) $this->duration_ms;
        }

        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) max(0, $this->started_at->diffInMilliseconds($this->finished_at));
    }
}
