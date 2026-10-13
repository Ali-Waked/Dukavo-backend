<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hard tenant isolation at the query layer.
 *
 * Every tenant-owned model applies this scope, so a forgotten `where('tenant_id', ...)`
 * in a service or controller still cannot leak another merchant's rows. The
 * scope fails closed: without a resolved tenant it matches nothing instead of
 * everything. Platform endpoints that legitimately read across tenants must
 * call `withoutGlobalScope(TenantScope::class)` explicitly, which keeps the
 * cross-tenant decision visible in the code.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
