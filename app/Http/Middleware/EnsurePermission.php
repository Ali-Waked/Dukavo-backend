<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route level permission gate.
 *
 * Usage: `->middleware('can.access:products.update')`. Checking a permission
 * here is *necessary but not sufficient* — policies still guard record level
 * access — and it is always enforced server side. The Vue application uses the
 * same permission slugs to decide what to render, which is a UX concern only.
 */
class EnsurePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermissionTo($permission, $this->currentTenant())) {
                return $next($request);
            }
        }

        throw new AuthorizationException(
            sprintf('You do not have the "%s" permission.', implode('" or "', $permissions))
        );
    }

    /**
     * The active tenant, or NULL on platform endpoints where permissions are
     * evaluated against platform roles only.
     */
    private function currentTenant(): ?Tenant
    {
        return $this->context->tenant();
    }
}
