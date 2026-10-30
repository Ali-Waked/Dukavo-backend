<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\User;
use App\Services\Rbac\PermissionService;
use App\Support\ApiResponse;
use App\Support\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The payload the Vue application boots from.
 *
 * One request returns the user, the roles and permissions effective in each
 * tenant they can reach, and the tenant switcher's options. The frontend builds
 * its navigation and button visibility from exactly these permissions, which are
 * the same ones the backend enforces.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $tenants,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('platformRoles.permissions');

        $tenants = $user->tenants()->orderBy('tenants.name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'locale' => $user->locale,
                    'status' => $user->status->value,
                    'avatar_url' => $user->avatarUrl(),
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'is_platform_user' => $this->permissions->isPlatformUser($user),
                    'is_super_admin' => $this->permissions->isSuperAdmin($user),
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ],
                'active_tenant' => $this->activeTenant(),
                'tenants' => TenantResource::collection($tenants),
                'roles' => $this->rolesByTenant($user, $tenants),
                'permissions' => $this->permissions->permissionsFor($user, $this->tenants->tenant())->values(),
                'permission_catalogue' => PermissionRegistry::groupedByModule(),
            ],
        ]);
    }

    /**
     * End the session and revoke the token it was called with.
     *
     * Both are cleared: the cookie for a browser client, and the personal access
     * token for a client that authenticated with one, so a signed-out device
     * cannot continue on a credential it is still holding. Other devices keep
     * their own tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // A browser client authenticates by session and carries Sanctum's
        // `TransientToken`, which has nothing to revoke.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        // A bearer-only request never had a session, and asking for one here
        // would turn a successful sign-out into a 500.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success('Signed out.');
    }

    /**
     * The tenants the user may switch into, each with the permissions they hold
     * there, so switching tenants never needs a second round trip.
     *
     * @param  Collection<int, Tenant>  $tenants
     * @return list<array{tenant_id: int, slug: string, roles: list<string>, permissions: list<string>}>
     */
    private function rolesByTenant(User $user, Collection $tenants): array
    {
        return $tenants
            ->map(fn ($tenant): array => [
                'tenant_id' => $tenant->getKey(),
                'slug' => $tenant->slug,
                'roles' => $this->permissions->rolesFor($user, $tenant)->pluck('slug')->values()->all(),
                'permissions' => $this->permissions->permissionsFor($user, $tenant)->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeTenant(): ?array
    {
        $tenant = $this->tenants->tenant();

        if ($tenant === null) {
            return null;
        }

        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'is_active' => $tenant->isActive(),
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
            'locale' => $tenant->locale,
        ];
    }
}
