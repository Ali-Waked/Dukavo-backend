<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\PermissionService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to Dukavo platform operators.
 *
 * Platform endpoints manage tenants themselves, so they must not run inside a
 * tenant context. The middleware also verifies the tenant being acted upon is
 * never the operator's own "home" tenant when that would blur the boundary.
 */
class EnsurePlatformAccess
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->isOperator($user)) {
            throw new AuthorizationException('This area is restricted to Dukavo platform administrators.');
        }

        return $next($request);
    }

    /**
     * Guard used by the tenant routes the platform is allowed to enter.
     */
    public function handleForTenant(Request $request, Closure $next, Tenant $tenant): Response
    {
        if ($tenant->isActive()) {
            return $next($request);
        }

        throw new AuthorizationException('This tenant is suspended.');
    }

    /**
     * The platform role the operator actually holds, in either of the two system
     * roles: the super admin and the support admin.
     */
    private function isOperator(User $user): bool
    {
        return $this->permissions->isPlatformOperator($user);
    }
}
