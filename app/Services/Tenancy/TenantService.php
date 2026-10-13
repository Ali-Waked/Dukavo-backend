<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\StoreStatus;
use App\Enums\TenantStatus;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant lifecycle.
 *
 * Creating a tenant is a single atomic operation that also creates the
 * tenant's first store. Inventory is tracked per store, so a tenant without a
 * store could never hold stock — the invariant is established here rather than
 * left to every caller.
 */
class TenantService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTenant(array $attributes): Tenant
    {
        return DB::transaction(function () use ($attributes): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $attributes['name'],
                'slug' => $this->uniqueSlug($attributes['name'], $attributes['slug'] ?? null),
                'status' => $attributes['status'] ?? TenantStatus::Active,
                'contact_email' => $attributes['contact_email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'currency' => $attributes['currency'] ?? 'USD',
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'locale' => $attributes['locale'] ?? 'en',
                'settings' => $attributes['settings'] ?? null,
            ]);

            $this->createDefaultStore($tenant, $attributes['store_name'] ?? null);

            return $tenant;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTenant(Tenant $tenant, array $attributes): Tenant
    {
        if (isset($attributes['status'])) {
            $status = $attributes['status'] instanceof TenantStatus
                ? $attributes['status']
                : TenantStatus::from((string) $attributes['status']);

            // The suspension timestamp is derived, never accepted from the
            // client: it is what billing and support reason about. It is set
            // directly rather than filled because it is not mass assignable.
            $tenant->suspended_at = $status === TenantStatus::Suspended
                ? ($tenant->suspended_at ?? now())
                : null;
        }

        if (isset($attributes['name']) && ! isset($attributes['slug'])) {
            $attributes['slug'] = $this->uniqueSlug($attributes['name'], null, $tenant->getKey());
        }

        $tenant->fill($attributes)->save();

        return $tenant;
    }

    /**
     * Update the settings a merchant may change about their own workspace.
     *
     * Deliberately narrower than {@see updateTenant()}: it never regenerates the
     * slug when the name changes and never touches `status`, so the stable
     * identifier the tenant header depends on survives a rename. The tenant model
     * is auditable, so the save() records the change on its own.
     *
     * @param  array<string, mixed>  $attributes  validated UpdateTenantSettingsRequest input
     */
    public function updateWorkspaceSettings(Tenant $tenant, array $attributes): Tenant
    {
        $editable = ['name', 'contact_email', 'phone', 'currency', 'timezone', 'locale'];

        $tenant->fill(array_intersect_key($attributes, array_flip($editable)))->save();

        return $tenant;
    }

    public function suspend(Tenant $tenant): Tenant
    {
        return $this->updateTenant($tenant, ['status' => TenantStatus::Suspended]);
    }

    public function activate(Tenant $tenant): Tenant
    {
        return $this->updateTenant($tenant, ['status' => TenantStatus::Active]);
    }

    /**
     * Every tenant gets exactly one default store, created inside a tenant
     * context so the global scope and the automatic `tenant_id` stamp agree.
     */
    public function createDefaultStore(Tenant $tenant, ?string $name = null): Store
    {
        return $this->tenants->run($tenant, fn (): Store => $tenant->stores()->create([
            'name' => $name ?? $tenant->name,
            'slug' => 'default',
            'status' => StoreStatus::Active,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
            'is_default' => true,
        ]));
    }

    /**
     * Provision a tenant for a freshly registered user in one atomic step.
     *
     * The tenant, its default store and the caller's owner role either all
     * exist afterwards or none of them do: a tenant nobody owns is an orphan
     * no screen can reach, and a user attached to a tenant without a role can
     * do nothing at all. The merchant role lookup is deliberately inside the
     * transaction — if the role table is not seeded, the whole operation rolls
     * back instead of leaving a half-provisioned workspace behind.
     *
     * @param  array<string, mixed>  $attributes  validated ProvisionTenantRequest input
     */
    public function provisionFor(User $user, array $attributes): Tenant
    {
        return DB::transaction(function () use ($user, $attributes): Tenant {
            $attributes['contact_email'] ??= $user->email;

            // createTenant() and addUser() open nested transactions, which
            // Laravel runs as savepoints — an outer rollback undoes them all.
            $tenant = $this->createTenant($attributes);

            $role = Role::query()->where('slug', 'merchant')->firstOrFail();

            $this->addUser($tenant, $user, $role, isOwner: true);

            return $tenant;
        });
    }

    /**
     * Add a user to a tenant and grant them a tenant scoped role in one step.
     *
     * Used by registration and by the tenant "invite user" flow so a member can
     * never exist without a role (a member without a role can do nothing).
     */
    public function addUser(Tenant $tenant, User $user, Role $role, bool $isOwner = false): void
    {
        DB::transaction(function () use ($tenant, $user, $role, $isOwner): void {
            $tenant->users()->syncWithoutDetaching([$user->getKey() => ['is_owner' => $isOwner]]);

            $user->roleAssignments()->updateOrCreate(
                ['role_id' => $role->getKey(), 'tenant_id' => $tenant->getKey()],
                [],
            );

            $this->permissions->flush($user);
        });
    }

    public function removeUser(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user): void {
            $tenant->users()->detach($user->getKey());

            $user->roleAssignments()->where('tenant_id', $tenant->getKey())->delete();

            $this->permissions->flush($user);
        });
    }

    /**
     * Tenants available to the given user. Platform staff may operate any
     * tenant; everyone else only sees tenants they belong to.
     *
     * @return Collection<int, Tenant>
     */
    public function tenantsFor(User $user): Collection
    {
        if ($this->permissions->isPlatformOperator($user)) {
            return Tenant::query()->orderBy('name')->get();
        }

        return Tenant::query()
            ->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))
            ->orderBy('name')
            ->get();
    }

    /**
     * Run a callback as the tenant, used when internal jobs need tenant data
     * outside an HTTP request.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function within(Tenant $tenant, callable $callback): mixed
    {
        return $this->tenants->run($tenant, $callback);
    }

    private function uniqueSlug(string $name, ?string $requested = null, ?int $ignoreId = null): string
    {
        $base = Str::slug($requested ?: $name) ?: 'tenant';
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
