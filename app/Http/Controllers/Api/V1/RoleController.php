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
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\PermissionService;
use App\Services\Rbac\RoleService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Roles and their permission sets.
 *
 * Which roles a caller may see depends on where they are. Inside a tenant the
 * visible set is the global system templates plus the roles that workspace
 * created; a custom role owned by another tenant is a 404, never a readable
 * row. In platform mode the catalogue is the global roles only (platform roles
 * and the system tenant templates); a merchant's custom roles are not Dukavo's
 * to manage.
 *
 * Every mutation is audited. The audit row lands in the caller's current scope:
 * the active tenant's trail when the request ran through the tenant group, the
 * platform trail when it ran through the platform group.
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
        $tenant = $this->tenants->tenant();

        $roles = Role::query()
            ->when(
                $tenant !== null,
                // Inside a workspace: the shared system templates plus this
                // tenant's own custom roles, never another tenant's.
                fn ($query) => $query->visibleToTenant($tenant->getKey()),
                // Platform catalogue: global roles only. Tenant owned roles are
                // a merchant's, not Dukavo's, and never appear here.
                fn ($query) => $query->whereNull('tenant_id'),
            )
            ->when($request->filled('scope'), fn ($query) => $query->where('scope', $request->string('scope')->toString()))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->with('permissions')
            ->orderBy('scope')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $scope = RoleScope::from($request->validated('scope'));

        $role = $this->roles->createRole(
            name: $request->validated('name'),
            scope: $scope,
            description: $request->validated('description'),
            permissionSlugs: $request->validated('permissions', []),
            // A platform role is global; a tenant role is owned by the
            // workspace the request is running in.
            tenant: $scope === RoleScope::Tenant ? $this->tenants->required() : null,
            actor: $request->user(),
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
        $this->assertRoleIsManageable($role, $request->user());

        $role->load('permissions');

        return new RoleResource($role);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->assertRoleIsManageable($role, $request->user());

        $before = $role->only(['name', 'description']);
        $updated = $this->roles->updateRole($role, $request->validated(), $request->user());
        $after = $updated->only(['name', 'description']);

        $this->audit->recordChanges(AuditAction::Updated, $role, $before, $after);

        return new RoleResource($updated);
    }

    public function destroy(Request $request, Role $role): Response
    {
        $this->assertRoleIsManageable($role, $request->user());

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
        $this->assertRoleIsManageable($role, $request->user());

        $before = $role->load('permissions')->permissions->pluck('slug')->sort()->values()->all();

        $role = $this->roles
            ->syncPermissions($role, $request->validated('permissions'), $request->user(), $role->tenant)
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
        $this->assertRoleIsManageable($role, $request->user());

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
        $this->assertRoleIsManageable($role, $request->user());

        /** @var User $user */
        $user = User::query()->findOrFail($request->validated('user_id'));

        $result = $this->roles->revoke($user, $role, $this->tenants->required());

        return ApiResponse::success('Role revoked.', ['user_id' => $user->getKey()] + $result);
    }

    /**
     * Route model binding resolves a role by id alone, so the id is not proof
     * of anything. Two boundaries are enforced here for every action:
     *
     *  - platform roles are Dukavo's own; only a platform operator may touch
     *    one, otherwise a merchant reaching a role by id could edit it or, via
     *    `assign`, grant themselves a platform role;
     *  - a custom tenant role is owned by exactly one workspace; from any other
     *    workspace it is a 404, so the endpoint never confirms it exists.
     *
     * Global system templates (`tenant_id` NULL) fall through: they are shared
     * by every workspace and the route's `permission:` middleware is the guard
     * on who may change them.
     */
    private function assertRoleIsManageable(Role $role, ?User $user): void
    {
        if ($role->scope === RoleScope::Platform) {
            if ($user === null || ! $this->permissions->isPlatformOperator($user)) {
                throw new AuthorizationException('Platform roles can only be managed by Dukavo platform administrators.');
            }

            return;
        }

        if ($role->tenant_id === null) {
            return;
        }

        $tenant = $this->tenants->tenant();

        if ($tenant === null || $role->tenant_id !== $tenant->getKey()) {
            throw (new ModelNotFoundException)->setModel(Role::class, [$role->getKey()]);
        }
    }
}
