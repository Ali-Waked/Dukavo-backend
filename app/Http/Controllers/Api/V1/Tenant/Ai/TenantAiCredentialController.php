<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Ai\StoreTenantAiCredentialRequest;
use App\Http\Requests\Tenant\Ai\TestTenantAiCredentialRequest;
use App\Http\Resources\Ai\AiProviderCredentialResource;
use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Services\Ai\Admin\AiAdminService;
use App\Services\Ai\AiSettings;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The workspace's own provider credentials (BYOK).
 *
 * Strictly isolated to the resolved tenant: the id a credential belongs
 * to comes from {@see TenantContext}, never from the request, and every
 * read and revoke re-checks ownership. The platform credential is never
 * visible here, and this surface only exists while the platform has
 * enabled BYOK.
 */
final class TenantAiCredentialController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AiAdminService $admin,
        private readonly AiSettings $policy,
    ) {}

    /**
     * The workspace's credentials (masked) plus the providers it may bring
     * a key for.
     */
    public function index(): JsonResponse
    {
        $tenant = $this->tenants->required();

        $credentials = AiProviderCredential::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereNull('revoked_at')
            ->with('provider')
            ->orderByDesc('updated_at')
            ->get();

        return ApiResponse::success('', [
            'byok' => [
                'platform_enabled' => $this->policy->byokEnabled(),
                'opted_in' => $this->policy->tenantByokOptIn($tenant),
            ],
            'credentials' => AiProviderCredentialResource::collection($credentials)->resolve(),
            'providers' => AiProvider::query()
                ->where('enabled', true)
                ->orderBy('priority')
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'driver'])
                ->map(fn (AiProvider $provider): array => [
                    'id' => $provider->id,
                    'key' => $provider->key,
                    'name' => $provider->name,
                    'driver' => $provider->driver,
                ])
                ->all(),
        ]);
    }

    /**
     * Store or rotate the workspace's credential for one provider.
     */
    public function store(StoreTenantAiCredentialRequest $request): JsonResponse
    {
        if (! $this->policy->byokEnabled()) {
            return ApiResponse::error('Bring-your-own-key is not enabled by the platform.', 403);
        }

        $tenant = $this->tenants->required();

        $provider = AiProvider::query()
            ->where('key', (string) $request->validated('provider'))
            ->where('enabled', true)
            ->firstOrFail();

        try {
            $credential = $this->admin->storeCredential(
                $provider,
                (string) $request->validated('api_key'),
                tenantId: $tenant->getKey(),
                options: [
                    'base_url' => $request->validated('base_url'),
                    'model' => $request->validated('model'),
                ],
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        $credential->setRelation('provider', $provider);

        return ApiResponse::created('Credential stored.', new AiProviderCredentialResource($credential));
    }

    /**
     * Revoke the workspace's credential for one provider.
     */
    public function revoke(AiProviderCredential $credential): JsonResponse
    {
        $tenant = $this->tenants->required();

        if ($credential->tenant_id !== $tenant->getKey()) {
            abort(404);
        }

        $credential = $this->admin->revokeCredential($credential);

        return ApiResponse::success('Credential revoked.', new AiProviderCredentialResource($credential));
    }

    /**
     * Test the workspace's own credential for one provider. The check
     * exercises the tenant's key or nothing — it can never silently pass
     * against the platform's credential.
     */
    public function test(TestTenantAiCredentialRequest $request): JsonResponse
    {
        if (! $this->policy->byokEnabled()) {
            return ApiResponse::error('Bring-your-own-key is not enabled by the platform.', 403);
        }

        $tenant = $this->tenants->required();

        $provider = AiProvider::query()
            ->where('key', (string) $request->validated('provider'))
            ->where('enabled', true)
            ->firstOrFail();

        $result = $this->admin->testConnection($provider, $tenant);

        return ApiResponse::success('', [
            'provider' => [
                'id' => $provider->id,
                'key' => $provider->key,
                'name' => $provider->name,
            ],
            'result' => $result,
        ]);
    }
}
