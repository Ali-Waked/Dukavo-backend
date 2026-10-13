<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TenantNotResolvedException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant a request acts inside and binds it to the container.
 *
 * Resolution order:
 *   1. The `X-Tenant` header (slug or id). Accepted only if the authenticated
 *      user actually holds a role or membership in that tenant.
 *   2. The user's only tenant, when they belong to exactly one — this keeps the
 *      common single-tenant case free of any client-side plumbing.
 *
 * A `tenant_id` in the request *body or query* is never trusted. Platform
 * routes must not use this middleware at all; they resolve the tenant
 * explicitly from the URL.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new TenantNotResolvedException('Authentication is required to resolve a tenant.');
        }

        $tenant = $this->resolveTenant($request, $user);

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }

    /**
     * Resolve a tenant, or report that none could be.
     *
     * The boot endpoints need to answer for users who belong to no tenant, or to
     * several, so they ask this instead of handling the exception themselves.
     *
     * @see self::handle() for the resolution order
     */
    public function tryResolve(Request $request, User $user): ?Tenant
    {
        try {
            return $this->resolveTenant($request, $user);
        } catch (TenantNotResolvedException) {
            return null;
        }
    }

    private function resolveTenant(Request $request, User $user): Tenant
    {
        $header = trim((string) $request->header(config('dukavo.tenant_header', 'X-Tenant')));

        if ($header !== '') {
            $tenant = Tenant::query()
                ->where(fn ($query) => $query->where('slug', $header)->orWhere('id', ctype_digit($header) ? (int) $header : 0))
                ->first();

            if (! $tenant instanceof Tenant) {
                throw new TenantNotResolvedException('The requested tenant does not exist.');
            }

            // Membership alone is not enough: the user must hold a role inside
            // the tenant, otherwise the tenant has granted them nothing.
            $hasRole = $user->roleAssignments()->where('tenant_id', $tenant->getKey())->exists();
            $isPlatformOperator = $user->isSuperAdmin() || $user->hasRole('dukavo-admin');

            if (! $hasRole && ! $isPlatformOperator && ! $user->isMemberOf($tenant->getKey())) {
                throw new TenantNotResolvedException('You do not have access to this tenant.');
            }

            if (! $isPlatformOperator && ! $tenant->isActive()) {
                throw new TenantNotResolvedException('This tenant is currently suspended.');
            }

            return $tenant;
        }

        $tenant = $this->soleTenantFor($user);

        if (! $tenant instanceof Tenant) {
            throw new TenantNotResolvedException(
                'No tenant could be resolved. Send the X-Tenant header with one of your tenants.'
            );
        }

        return $tenant;
    }

    /**
     * A user with exactly one tenant never has to send the header. A user with
     * several must choose explicitly rather than inheriting whichever row the
     * database happened to return first.
     */
    private function soleTenantFor(User $user): ?Tenant
    {
        $isPlatformOperator = $user->isSuperAdmin() || $user->hasRole('dukavo-admin');

        if ($isPlatformOperator) {
            return null;
        }

        $tenantIds = $user->tenants()->pluck('tenants.id');

        if ($tenantIds->count() !== 1) {
            return null;
        }

        $tenant = Tenant::query()->find($tenantIds->first());

        if (! $tenant instanceof Tenant) {
            return null;
        }

        if ($user->roleAssignments()->where('tenant_id', $tenant->getKey())->doesntExist()) {
            return null;
        }

        return $tenant->isActive() ? $tenant : null;
    }
}
