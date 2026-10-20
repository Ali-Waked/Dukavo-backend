<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of permissions.
 *
 * A role is either platform scoped (Dukavo staff) or tenant scoped (merchant,
 * staff). Scope decides whether an assignment carries a `tenant_id`, which is
 * the mechanism that stops a merchant from being granted a platform role.
 *
 * `tenant_id` decides who *owns* a tenant scoped role. The seeded system roles
 * (`merchant`, `staff`) are global templates and stay NULL so every workspace
 * shares them; a role a merchant creates is owned by that tenant and is
 * invisible to every other tenant.
 */
#[Fillable(['name', 'slug', 'scope', 'description', 'is_system', 'tenant_id'])]
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
    public function permissions(): BelongsToMany
    {
        // The pivot table is named explicitly: Laravel's alphabetical guess would
        // be `permission_role`, while the migration created `role_permissions`.
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * The tenant that owns a custom tenant scoped role, or NULL for a global
     * (platform or system) role.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

    /**
     * Tenant scoped roles a workspace may see: the global system templates plus
     * the custom roles that workspace owns. A NULL tenant id means "shared".
     */
    #[Scope]
    protected function visibleToTenant(Builder $query, ?int $tenantId): void
    {
        $query->tenantScoped()->where(
            fn (Builder $inner) => $inner->whereNull('tenant_id')->orWhere('tenant_id', $tenantId),
        );
    }
}
