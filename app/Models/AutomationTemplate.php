<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationTemplateStatus;
use App\Enums\AutomationTrigger;
use Database\Factories\AutomationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-owned automation blueprint.
 *
 * Templates are global: they carry no `tenant_id` and are never edited by a
 * merchant. Installing one copies its definition into a tenant-owned
 * {@see AutomationRule}; subsequent template updates are offered to the
 * merchant instead of silently rewriting a customised rule.
 */
#[Fillable([
    'key', 'name', 'description', 'category', 'trigger', 'conditions', 'actions',
    'configuration_schema', 'icon', 'status', 'current_version', 'sort_order',
    'created_by', 'updated_by',
])]
class AutomationTemplate extends Model
{
    /** @use HasFactory<AutomationTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AutomationTemplateStatus::class,
            'trigger' => AutomationTrigger::class,
            'conditions' => 'array',
            'actions' => 'array',
            'configuration_schema' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<AutomationTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AutomationTemplateVersion::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<AutomationTemplateInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(AutomationTemplateInstallation::class);
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

    public function isInstallable(): bool
    {
        return $this->status->isInstallable();
    }

    /**
     * @return array{name: string, description: string, category: string, trigger: string, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, configuration_schema: list<array<string, mixed>>}
     */
    public function definition(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'trigger' => $this->trigger->value,
            'conditions' => $this->conditions ?? [],
            'actions' => $this->actions ?? [],
            'configuration_schema' => $this->configuration_schema ?? [],
        ];
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', AutomationTemplateStatus::Active);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
