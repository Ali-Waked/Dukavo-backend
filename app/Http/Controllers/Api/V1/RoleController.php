<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\RoleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\AssignRoleRequest;
use App\Http\Requests\Roles\RevokeRoleRequest;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\PermissionService;
use App\Services\Rbac\RoleService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Roles and their permission sets.
 *
 * Which roles a caller may see depends on where they are: inside a tenant only
 * tenant scoped roles are actionable, while platform operators see the whole
 * catalogue. The filter is applied once, here, rather than per route.
 *
 * Every mutation is audited. A role has no tenant of its own, so the audit
 * row lands in the caller's current scope: the active tenant's trail when the
 * request ran through the tenant group, the platform trail when it ran through
 * the platform group.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly PermissionService $permissions,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenants->id();

        $roles = Role::query()
            ->where(fn ($query) => $query
                ->platformScoped()
                ->orWhere('scope', RoleScope::Tenant->value))
            ->when($request->filled('scope'), fn ($query) => $query->where('scope', $request->string('scope')->toString()))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->with('permissions')
            ->orderBy('scope')
            ->orderBy('name')
            ->get();

        // A merchant may only see tenant roles while acting inside a tenant.
        if ($tenantId !== null) {
            $roles = $roles->filter(fn (Role $role): bool => $role->scope === RoleScope::Tenant);
        }

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->createRole(
            name: $request->validated('name'),
            scope: RoleScope::from($request->validated('scope')),
            description: $request->validated('description'),
            permissionSlugs: $request->validated('permissions', []),
        );

        $this->audit->record(AuditAction::Created, $role, [
            'new' => [
                'name' => $role->name,
                'scope' => $role->scope->value,
                'description' => $role->description,
            ],
        ]);

        return ApiResponse::created('Role created.', new RoleResource($role));
    }

    public function show(Request $request, Role $role): RoleResource
    {
        $this->assertPlatformRoleIsManageable($role, $request->user());

        $role->load('permissions');

        return new RoleResource($role);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->assertPlatformRoleIsManageable($role, $request->user());

        $before = $role->only(['name', 'description']);
        $updated = $this->roles->updateRole($role, $request->validated());
        $after = $updated->only(['name', 'description']);

        $this->audit->recordChanges(AuditAction::Updated, $role, $before, $after);

        return new RoleResource($updated);
    }

    public function destroy(Request $request, Role $role): Response
    {
        $this->assertPlatformRoleIsManageable($role, $request->user());

        $this->audit->record(AuditAction::Deleted, $role, [
            'new' => ['name' => $role->name, 'scope' => $role->scope->value],
        ]);

        $this->roles->deleteRole($role);

        return response()->noContent();
    }

    /**
     * Replace the role's permission set wholesale. A PUT that omits the list is
     * a mistake; a PUT of an empty list deliberately clears a custom role.
     */
    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->assertPlatformRoleIsManageable($role, $request->user());

        $before = $role->load('permissions')->permissions->pluck('slug')->sort()->values()->all();

        $role = $this->roles
            ->syncPermissions($role, $request->validated('permissions'))
            ->load('permissions');

        $after = $role->permissions->pluck('slug')->sort()->values()->all();

        $this->audit->record(AuditAction::PermissionChanged, $role, [
            'old' => ['permissions' => $before],
            'new' => ['permissions' => $after],
        ]);

        return ApiResponse::success('Permissions updated.', new RoleResource($role));
    }

    /**
     * Grant a role inside the active tenant. The membership check lives in the
     * service, where it also covers non-HTTP callers.
     */
    public function assign(AssignRoleRequest $request, Role $role): JsonResponse
    {
        $this->assertPlatformRoleIsManageable($role, $request->user());

        /** @var User $user */
        $user = User::query()->findOrFail($request->validated('user_id'));
        $tenant = $this->tenants->required();

        $this->roles->assign($user, $role, $tenant);

        return ApiResponse::created('Role assigned.', [
            'user_id' => $user->getKey(),
            'role' => $role->slug,
            'tenant_id' => $role->scope === RoleScope::Platform ? null : $tenant->getKey(),
        ]);
    }

    /**
     * Remove a role from a user. Revoking what a user does not hold changes
     * nothing, so the endpoint stays idempotent rather than failing.
     */
    public function revoke(RevokeRoleRequest $request, Role $role): JsonResponse
    {
        $this->assertPlatformRoleIsManageable($role, $request->user());

        /** @var User $user */
        $user = User::query()->findOrFail($request->validated('user_id'));

        $result = $this->roles->revoke($user, $role, $this->tenants->required());

        return ApiResponse::success('Role revoked.', ['user_id' => $user->getKey()] + $result);
    }

    /**
     * Platform scoped roles are Dukavo's own, never a tenant's. Route model
     * binding resolves a role by id alone, so without this check a merchant
     * who reached a platform role by id could edit it — or, through `assign`,
     * grant themselves a platform role. Every action in this controller runs
     * inside the tenant group, so one check here covers all of them.
     */
    private function assertPlatformRoleIsManageable(Role $role, ?User $user): void
    {
        if ($role->scope !== RoleScope::Platform) {
            return;
        }

        if ($user === null || ! $this->permissions->isPlatformOperator($user)) {
            throw new AuthorizationException('Platform roles can only be managed by Dukavo platform administrators.');
        }
    }
}
