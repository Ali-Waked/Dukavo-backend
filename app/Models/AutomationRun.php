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

/**
 * An append-only record of one rule evaluation.
 *
 * `event_key` is unique per rule, which makes execution safe under queue
 * retries and repeated event delivery. Runs are never edited by merchants.
 */
#[Fillable([
    'tenant_id', 'automation_rule_id', 'trigger', 'event_key', 'status',
    'matched', 'context', 'actions', 'error', 'started_at', 'finished_at',
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
            'matched' => 'boolean',
            'context' => 'array',
            'actions' => 'array',
            'started_at' => 'datetime',
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
}
