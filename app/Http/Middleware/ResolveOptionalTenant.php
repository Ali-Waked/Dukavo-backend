<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a tenant when one is available and carries on when it is not.
 *
 * The boot endpoints have to describe a user who may belong to no tenant at all
 * — a platform operator, or a merchant whose tenant is suspended — and who may
 * belong to several. Forcing those requests through {@see ResolveTenant} would
 * either refuse to describe the user's own account or pick a tenant on their
 * behalf, which is what the account switcher exists to avoid.
 *
 * It never widens access: a request that resolved no tenant runs with no tenant
 * context, and every tenant-scoped query in it then fails closed through
 * `TenantScope`.
 */
class ResolveOptionalTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ResolveTenant $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $tenant = $this->resolver->tryResolve($request, $user);

        if ($tenant === null) {
            return $next($request);
        }

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
