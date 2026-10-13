<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join between a user, a role and — for tenant scoped roles — the tenant the
 * role is effective in.
 *
 * A NULL `tenant_id` marks a platform role assignment. The unique index on
 * (user_id, role_id, tenant_id) is what makes "one user, one role per tenant"
 * a database guarantee rather than an application convention.
 */
#[Fillable(['user_id', 'role_id', 'tenant_id'])]
class UserRole extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPlatformAssignment(): bool
    {
        return $this->tenant_id === null;
    }
}
