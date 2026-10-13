<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ProvisionTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * First-run onboarding for a freshly registered user.
 *
 * Deliberately outside the tenant middleware: the caller has no tenant yet —
 * that is the entire point of this endpoint. The request may describe the
 * workspace, but never a role: the service makes the caller the owner of the
 * merchant role itself, so no payload can promote anybody into anything else.
 *
 * Provisioning is made idempotent here. A double-submitted form or a client
 * retry after a dropped response would otherwise mint a second workspace (the
 * slug helper happily suffixes `-2`), so a caller may send an
 * `Idempotency-Key` header and a replay returns the workspace it already
 * created instead of building another.
 */
class OnboardingController extends Controller
{
    /** How long a submitted key keeps pointing at the workspace it created. */
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(private readonly TenantService $tenants) {}

    public function storeTenant(ProvisionTenantRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            $tenant = $this->tenants->provisionFor($user, $request->safe()->all());

            return ApiResponse::created('Workspace created.', new TenantResource($tenant->load('stores')));
        }

        $cacheKey = 'onboarding:tenant:'.$user->getKey().':'.$key;

        $existing = $this->remembered($cacheKey, $user);

        if ($existing !== null) {
            return ApiResponse::success('Workspace already created.', new TenantResource($existing->load('stores')));
        }

        $tenant = $this->tenants->provisionFor($user, $request->safe()->all());

        Cache::put($cacheKey, $tenant->getKey(), now()->addHours(self::IDEMPOTENCY_TTL_HOURS));

        return ApiResponse::created('Workspace created.', new TenantResource($tenant->load('stores')));
    }

    /**
     * The workspace a previous submission with the same key created, if it
     * still exists and still belongs to the caller. A key pointing at a
     * workspace that was since removed (or never belonged to this user) is
     * treated as absent so the request falls through to a fresh provision.
     */
    private function remembered(string $cacheKey, User $user): ?Tenant
    {
        $tenantId = Cache::get($cacheKey);

        if ($tenantId === null) {
            return null;
        }

        return Tenant::query()
            ->whereKey($tenantId)
            ->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))
            ->first();
    }
}
