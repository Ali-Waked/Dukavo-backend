<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\UpdateTenantSettingsRequest;
use App\Http\Resources\TenantResource;
use App\Services\Tenancy\TenantService;
use App\Support\PermissionRegistry;
use App\Support\Tenancy\TenantContext;

/**
 * Workspace settings of the active tenant.
 *
 * The tenant comes from {@see TenantContext}, never a route, so a user can only
 * ever read and change the workspace they currently act in. The
 * `settings.view` / `settings.manage` permissions decide who may look and who
 * may change; see {@see PermissionRegistry}.
 */
class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantService $tenantService,
    ) {}

    public function show(): TenantResource
    {
        return new TenantResource($this->tenants->required());
    }

    public function update(UpdateTenantSettingsRequest $request): TenantResource
    {
        $tenant = $this->tenantService->updateWorkspaceSettings($this->tenants->required(), $request->validated());

        return new TenantResource($tenant);
    }
}
