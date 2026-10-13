<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of permissions.
 *
 * A role is either platform scoped (Dukavo staff) or tenant scoped (merchant,
 * staff). Scope is what the assignment layer uses to decide whether a
 * `tenant_id` is required, which is the mechanism that stops a merchant from
 * being granted a platform role.
 */
#[Fillable(['name', 'slug', 'scope', 'description', 'is_system'])]
class Role extends Model
{
    /**
     * Slugs of the roles that ship with Dukavo. They are seeded rather than
     * created through the UI, so they are protected from deletion and from
     * having their scope changed.
     *
     * @var list<string>
     */
    public const SYSTEM_SLUGS = ['super-admin', 'dukavo-admin', 'merchant', 'staff'];

    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        // The pivot table is named explicitly: Laravel's alphabetical guess would
        // be `permission_role`, while the migration created `role_permissions`.
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function requiresTenant(): bool
    {
        return $this->scope->requiresTenant();
    }

    #[Scope]
    protected function platformScoped(Builder $query): void
    {
        $query->where('scope', RoleScope::Platform);
    }

    #[Scope]
    protected function tenantScoped(Builder $query): void
    {
        $query->where('scope', RoleScope::Tenant);
    }
}
