<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Ai\UpdateTenantAiSettingsRequest;
use App\Models\Tenant;
use App\Services\Ai\Tenant\TenantAiSettingsService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The workspace's AI settings.
 *
 * The tenant comes from {@see TenantContext}, never a route parameter, so
 * a merchant can only ever read and change the workspace they currently
 * act in. Everything offered here can only narrow what the platform
 * allows.
 */
final class TenantAiSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantAiSettingsService $settings,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = $this->tenants->required();

        return ApiResponse::success('', $this->settings->overview($tenant));
    }

    public function update(UpdateTenantAiSettingsRequest $request): JsonResponse
    {
        $tenant = $this->tenants->required();

        if ($request->has('features')) {
            try {
                $this->settings->syncFeatures($tenant, (array) $request->validated('features'));
            } catch (\InvalidArgumentException $exception) {
                return ApiResponse::error($exception->getMessage(), 422);
            }
        }

        if ($request->has('byok_opt_in')) {
            $changed = $this->settings->setByokOptIn($tenant, (bool) $request->validated('byok_opt_in'));

            if (! $changed) {
                return ApiResponse::error('Bring-your-own-key is not enabled by the platform, so the workspace cannot opt in.', 403);
            }
        }

        return ApiResponse::success('AI settings updated.', $this->settings->overview($tenant->refresh()));
    }
}
