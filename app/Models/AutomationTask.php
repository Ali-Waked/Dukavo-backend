<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationTaskStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AutomationTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal follow-up work created by an automation rule.
 *
 * Tasks are intentionally a manual queue, not an execution mechanism: they
 * surface something a person should inspect and never mutate business records
 * on their own.
 */
#[Fillable([
    'tenant_id', 'automation_rule_id', 'automation_run_id', 'store_id',
    'assigned_to', 'created_by', 'completed_by', 'title', 'description',
    'priority', 'status', 'entity_type', 'entity_id', 'metadata', 'due_at',
    'completed_at',
])]
class AutomationTask extends Model
{
    /** @use HasFactory<AutomationTaskFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['title', 'priority', 'status', 'assigned_to', 'due_at'];
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationTaskStatus::class,
            'metadata' => 'array',
            'entity_id' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<AutomationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === AutomationTaskStatus::Open;
    }
}
