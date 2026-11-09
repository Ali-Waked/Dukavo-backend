<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationInstallationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Links a store-scoped automation rule to the platform template it came from.
 *
 * The rule remains the executable object; this row owns provenance, the
 * installed version and the merchant's template configuration. It is what makes
 * an update safe to offer: a customised rule is never overwritten automatically.
 */
#[Fillable([
    'tenant_id', 'store_id', 'automation_template_id', 'automation_rule_id',
    'scope_key', 'installed_version', 'configuration', 'status',
    'template_customized', 'installed_by', 'updated_by', 'installed_at',
    'enabled_at', 'last_synced_at',
])]
class AutomationTemplateInstallation extends Model
{
    use BelongsToTenant {
        booted as protected bootTenant;
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationInstallationStatus::class,
            'configuration' => 'array',
            'template_customized' => 'boolean',
            'installed_at' => 'datetime',
            'enabled_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::bootTenant();

        static::creating(function (self $installation): void {
            $installation->scope_key ??= self::scopeKey($installation->store_id);
        });
    }

    public static function scopeKey(?int $storeId): string
    {
        return $storeId !== null ? 'store:'.$storeId : 'tenant';
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<AutomationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AutomationTemplate::class, 'automation_template_id');
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<AutomationInstallationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AutomationInstallationEvent::class, 'automation_template_installation_id')
            ->orderByDesc('id');
    }

    public function hasUpdate(?AutomationTemplate $template = null): bool
    {
        $template ??= $this->template;

        return $template instanceof AutomationTemplate
            && version_compare($template->current_version, $this->installed_version, '>');
    }

    #[Scope]
    protected function installed(Builder $query): void
    {
        $query->whereIn('status', [
            AutomationInstallationStatus::Installed->value,
            AutomationInstallationStatus::Disabled->value,
        ]);
    }
}
