<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned and wires up isolation.
 *
 * Provides the `tenant()` relation, an always-applied {@see TenantScope}, and
 * a `boot` hook that stamps `tenant_id` from the resolved context on create so
 * a client can never choose which tenant a new record belongs to.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return array<int, object>
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            // An explicit tenant_id may only ever be set by trusted internal
            // code (seeding, platform tooling); anything else takes the context.
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->id());
            }

            if ($model->getAttribute('tenant_id') === null) {
                throw new \RuntimeException(
                    sprintf('Cannot persist %s without a resolved tenant context.', $model::class)
                );
            }
        });
    }

    /**
     * Cross-tenant query builder for platform-level operations only.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)->where($this->qualifyColumn('tenant_id'), $tenantId);
    }
}
