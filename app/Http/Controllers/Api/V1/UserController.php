<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleService;
use App\Services\Users\UserService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The tenant's team.
 *
 * Only users who are members of the active tenant can appear here, which is what
 * the membership table decides — a user who belongs to a different tenant is
 * simply not in the result set, never merely hidden by a filter.
 *
 * Platform administrators are Dukavo staff, not tenant team members: their
 * membership exists so they can switch into the workspace, but they are managed
 * through the platform endpoints so a merchant can never suspend or hijack a
 * platform account from the team page.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly RoleService $roles,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenant = $this->tenants->required();

        $users = User::query()
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
            ->whereDoesntHave('roleAssignments', fn ($query) => $query->whereNull('tenant_id'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->with('roles')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $tenant = $this->tenants->required();
        $role = Role::query()->findOrFail($request->validated('role_id'));

        $user = $this->users->createForTenant($tenant, $role, $request->safe()->except('role_id'));

        return ApiResponse::created('Team member added.', new UserResource($user->load('roles')));
    }

    public function show(User $user): UserResource
    {
        // `users` is a global table: the id alone proves nothing, so membership of
        // the active tenant is asserted before anything is read. A user of another
        // tenant is a 404 rather than a 403, so the endpoint does not confirm that
        // the id exists.
        $this->authorizeTeamMember($user);

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorizeTeamMember($user);

        $this->users->updateUser($user, $request->safe()->except('role_id'));

        if ($request->has('role_id')) {
            $role = Role::query()->findOrFail($request->validated('role_id'));
            $tenant = $this->tenants->required();

            // The role is replaced, not added: one primary role per member keeps
            // "who can do what" answerable at a glance.
            foreach ($user->tenantRoleAssignments($tenant->getKey())->get() as $assignment) {
                $this->roles->revoke($user, $assignment->role, $tenant);
            }

            $this->roles->assign($user, $role, $tenant);
        }

        return new UserResource($user->fresh(['roles']));
    }

    public function destroy(User $user): Response
    {
        $this->authorizeTeamMember($user);

        // Deactivating rather than deleting: the user may hold rows in orders and
        // conversations, and an audit trail that loses its actors is worthless.
        $this->users->suspend($user);

        return response()->noContent();
    }

    /**
     * Refuse any user who is not a member of the active tenant, and any
     * platform administrator — Dukavo staff are managed through the platform
     * endpoints, so a merchant can never lock them out or reassign them here.
     */
    private function authorizeTeamMember(User $user): void
    {
        abort_unless(
            $user->tenants()->whereKey($this->tenants->required()->getKey())->exists(),
            404,
        );

        if ($user->platformRoles()->exists()) {
            throw new AuthorizationException('Platform administrators can only be managed by Dukavo platform staff.');
        }
    }

    /**
     * The roles available when inviting or reassigning a team member.
     */
    public function assignableRoles(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->tenantScoped()->with('permissions')->orderBy('name')->get()
        );
    }
}
