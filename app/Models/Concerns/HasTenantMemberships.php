<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user can hold different roles in different tenants, so every role query
 * must know which tenant it is being evaluated for.
 */
trait HasTenantMemberships
{
    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot(['is_owner'])
            ->withTimestamps();
    }

    public function isMemberOf(int $tenantId): bool
    {
        return $this->tenants()->whereKey($tenantId)->exists();
    }
}
