<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTrigger;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Automation\AutomationDefinition;
use Database\Factories\AutomationRuleFactory;
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
 * A merchant-defined "when this happens, do that" rule.
 *
 * `conditions` and `actions` are stored as structured JSON, never as code.
 * The service layer validates every key against {@see AutomationDefinition}
 * before saving, so a rule can only use fields its trigger really provides.
 */
#[Fillable([
    'tenant_id', 'store_id', 'name', 'description', 'status', 'trigger',
    'conditions', 'actions', 'cooldown_seconds', 'created_by', 'updated_by',
    'run_count', 'last_triggered_at',
])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'status', 'trigger', 'store_id', 'cooldown_seconds'];
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationRuleStatus::class,
            'trigger' => AutomationTrigger::class,
            'conditions' => 'array',
            'actions' => 'array',
            'cooldown_seconds' => 'integer',
            'run_count' => 'integer',
            'last_triggered_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return HasOne<AutomationRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(AutomationRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<AutomationTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AutomationTask::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', AutomationRuleStatus::Active);
    }

    #[Scope]
    protected function forTrigger(Builder $query, AutomationTrigger|string $trigger): void
    {
        $query->where('trigger', $trigger instanceof AutomationTrigger ? $trigger->value : $trigger);
    }

    #[Scope]
    protected function forStore(Builder $query, ?int $storeId): void
    {
        $query->where(function (Builder $query) use ($storeId): void {
            $query->whereNull('store_id');

            if ($storeId !== null) {
                $query->orWhere('store_id', $storeId);
            }
        });
    }
}
