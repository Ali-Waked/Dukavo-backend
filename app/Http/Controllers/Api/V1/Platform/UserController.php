<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\AuditAction;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformUserRequest;
use App\Http\Requests\Platform\UpdatePlatformUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleService;
use App\Services\Users\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Dukavo's own staff.
 *
 * These endpoints run inside the `platform` middleware: only platform operators
 * can reach them. A platform account is a user who holds a platform scoped role
 * (a NULL `tenant_id` assignment); merchant accounts live in their tenant, are
 * managed through the tenant team endpoints, and are never listed here as if
 * they were Dukavo staff.
 *
 * Two invariants are enforced beyond the route gate:
 *  - only platform scoped roles may be granted or revoked here (a tenant role
 *    belongs to a workspace, not to Dukavo staff);
 *  - the last active super administrator can never be suspended or stripped of
 *    their role, so the platform cannot lock itself out.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly RoleService $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->whereHas('platformRoles')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when($request->filled('role_id'), fn ($query) => $query->whereHas(
                'platformRoles',
                fn ($roles) => $roles->whereKey($request->integer('role_id')),
            ))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->with(['roles' => fn ($query) => $query->platformScoped(), 'tenants'])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StorePlatformUserRequest $request): JsonResponse
    {
        /** @var Role $role */
        $role = Role::query()->findOrFail($request->validated('role_id'));

        $user = $this->users->createPlatformUser(
            $role,
            $request->safe()->except('role_id'),
        );

        $this->audit->record(AuditAction::Created, $user, [
            'new' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role->slug,
            ],
        ]);

        return ApiResponse::created(
            'Platform administrator created.',
            new UserResource($user->load('roles')),
        );
    }

    public function show(User $user): UserResource
    {
        // A user with no platform role is a merchant, not Dukavo staff; reading
        // them here would blur the distinction the UI is built around.
        if ($user->platformRoles()->doesntExist()) {
            abort(404);
        }

        return new UserResource($user->load(['roles', 'tenants']));
    }

    public function update(UpdatePlatformUserRequest $request, User $user): UserResource
    {
        $before = $this->profileSnapshot($user);

        if ($request->filled('status') && $request->enum('status', UserStatus::class) !== $user->status) {
            $this->assertMaySuspend($user);

            $user = $request->enum('status', UserStatus::class) === UserStatus::Suspended
                ? $this->users->suspend($user)
                : $this->users->changeStatus($user, $request->enum('status', UserStatus::class));
        }

        $this->users->updateUser($user, $request->safe()->except('status'));

        $this->audit->recordChanges(AuditAction::Updated, $user, $before, $this->profileSnapshot($user), [
            'updated_at',
            'created_at',
            'remember_token',
            'password',
            'email_verified_at',
            'last_login_at',
        ]);

        return new UserResource($user->fresh(['roles']));
    }

    public function assignRole(Request $request, User $user, Role $role): JsonResponse
    {
        $this->assertPlatformRole($role);

        if ($user->platformRoles()->whereKey($role->getKey())->exists()) {
            return ApiResponse::success('Role assignment unchanged.', [
                'user_id' => $user->getKey(),
                'role' => $role->slug,
                'tenant_id' => null,
            ]);
        }

        $this->roles->assign($user, $role, null);

        $this->audit->record(AuditAction::RoleChanged, $user, [
            'old' => ['role' => 'none', 'tenant_id' => null],
            'new' => ['role' => $role->slug, 'tenant_id' => null],
        ]);

        return ApiResponse::created('Platform role assigned.', [
            'user_id' => $user->getKey(),
            'role' => $role->slug,
            'tenant_id' => null,
        ]);
    }

    public function revokeRole(User $user, Role $role): Response
    {
        $this->assertPlatformRole($role);
        $this->assertMayRevoke($user, $role);

        $this->roles->revoke($user, $role, null);

        $this->audit->record(AuditAction::RoleChanged, $user, [
            'old' => ['role' => $role->slug, 'tenant_id' => null],
            'new' => ['role' => 'none', 'tenant_id' => null],
        ]);

        return response()->noContent();
    }

    /**
     * Only platform scoped roles belong to Dukavo staff. A tenant role arrives
     * here only through a crafted request, and must never be attachable.
     */
    private function assertPlatformRole(Role $role): void
    {
        if ($role->scope !== RoleScope::Platform) {
            throw BusinessRuleViolation::because('Only platform roles can be assigned to platform staff.');
        }
    }

    /**
     * Suspending or downgrading the platform's last super administrator would
     * strip Dukavo of its own access; the refusal is a business rule because it
     * protects the platform, not a permission of the caller.
     */
    private function assertMaySuspend(User $user): void
    {
        if ($user->status !== UserStatus::Active) {
            return;
        }

        if ($user->platformRoles()->where('slug', 'super-admin')->doesntExist()) {
            return;
        }

        if (! $this->hasAnotherActiveSuperAdmin($user)) {
            throw BusinessRuleViolation::because('The last active super administrator cannot be suspended.');
        }
    }

    private function assertMayRevoke(User $user, Role $role): void
    {
        if ($role->slug !== 'super-admin') {
            return;
        }

        if (! $user->platformRoles()->whereKey($role->getKey())->exists()) {
            return;
        }

        if (! $this->hasAnotherActiveSuperAdmin($user)) {
            throw BusinessRuleViolation::because('The last super administrator cannot be downgraded.');
        }
    }

    private function hasAnotherActiveSuperAdmin(User $user): bool
    {
        return UserRole::query()
            ->where('role_id', Role::query()->where('slug', 'super-admin')->value('id'))
            ->where('tenant_id', null)
            ->where('user_id', '!=', $user->getKey())
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active->value))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'status' => $user->status->value,
        ];
    }
}
