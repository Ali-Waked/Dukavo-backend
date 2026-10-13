<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Http\Requests\Platform\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleService;
use App\Services\Tenancy\TenantService;
use App\Services\Users\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Tenant administration.
 *
 * These routes run *outside* any tenant context — there is no `tenant` middleware
 * on them — because managing tenants is the platform's job. Inside a tenant, the
 * same actions are performed by the merchant through the tenant endpoints.
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly UserService $users,
        private readonly RoleService $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('slug', 'like', '%'.$request->string('search')->toString().'%')))
            ->withCount(['stores', 'users'])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return TenantResource::collection($tenants);
    }

    /**
     * Provision a tenant, its default store and (optionally) its owner.
     *
     * One transaction covers all three: a tenant left behind by a failed owner
     * creation has a store and no way in, which is worse than no tenant at all.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $attributes = $request->safe()->except('owner');
        $owner = $request->validated('owner');

        $tenant = DB::transaction(function () use ($attributes, $owner, $request): Tenant {
            $tenant = $this->tenants->createTenant($attributes);

            // The owner is optional: platform staff may provision a tenant first
            // and invite the owner afterwards.
            if ($request->filled('owner.email')) {
                $this->users->createForTenant(
                    $tenant,
                    Role::query()->where('slug', 'merchant')->firstOrFail(),
                    $owner + ['is_owner' => true],
                );
            }

            return $tenant;
        });

        return ApiResponse::created('Tenant created.', new TenantResource($tenant->load('stores')));
    }

    public function show(Tenant $tenant): TenantResource
    {
        return new TenantResource($tenant->loadCount(['stores', 'users']));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        return new TenantResource($this->tenants->updateTenant($tenant, $request->validated()));
    }

    /**
     * Suspending a tenant keeps all of its data intact and simply blocks access.
     */
    public function suspend(Tenant $tenant): TenantResource
    {
        $this->audit->record(AuditAction::Updated, $tenant, [
            'old' => ['status' => TenantStatus::Active->value],
            'new' => ['status' => TenantStatus::Suspended->value],
        ]);

        return new TenantResource($this->tenants->suspend($tenant));
    }

    public function activate(Tenant $tenant): TenantResource
    {
        $this->audit->record(AuditAction::Updated, $tenant, [
            'old' => ['status' => TenantStatus::Suspended->value],
            'new' => ['status' => TenantStatus::Active->value],
        ]);

        return new TenantResource($this->tenants->activate($tenant));
    }

    public function members(Tenant $tenant): AnonymousResourceCollection
    {
        $tenant->load(['users' => fn ($query) => $query->with('platformRoles'), 'users.roleAssignments']);

        return UserResource::collection($tenant->users);
    }

    /**
     * There is no delete route on purpose: suspending keeps orders,
     * conversations and audit history intact and reversible, while a real
     * deletion is a platform-database operation, never an HTTP request.
     */
    public function destroy(Tenant $tenant): Response
    {
        $this->tenants->suspend($tenant);

        return response()->noContent();
    }
}
