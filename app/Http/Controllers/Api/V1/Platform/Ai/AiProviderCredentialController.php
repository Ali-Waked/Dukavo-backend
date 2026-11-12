<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Ai\StoreAiProviderCredentialRequest;
use App\Http\Resources\Ai\AiProviderCredentialResource;
use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Services\Ai\Admin\AiAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform administration of provider credentials.
 *
 * The most sensitive surface in the AI administration: the plaintext key
 * arrives in the store request, is encrypted by the service before it
 * reaches the database, and is never echoed back — every response carries
 * only the masked hint. The platform credential is the row with no tenant;
 * tenant rows are visible here masked (BYOK overview) but are managed by
 * the merchant in their own workspace.
 */
final class AiProviderCredentialController extends Controller
{
    public function __construct(private readonly AiAdminService $admin) {}

    /**
     * Every credential the provider has, masked.
     */
    public function index(AiProvider $provider): AnonymousResourceCollection
    {
        $credentials = $provider->credentials()
            ->with('tenant')
            ->orderByRaw('tenant_id IS NOT NULL, tenant_id')
            ->orderByDesc('updated_at')
            ->paginate(50);

        return AiProviderCredentialResource::collection($credentials);
    }

    /**
     * Store or rotate the platform credential for a provider.
     */
    public function store(StoreAiProviderCredentialRequest $request, AiProvider $provider): JsonResponse
    {
        $credential = $this->admin->storeCredential(
            $provider,
            (string) $request->validated('api_key'),
            tenantId: null,
            options: [
                'base_url' => $request->validated('base_url'),
                'model' => $request->validated('model'),
            ],
        );

        $credential->setRelation('provider', $provider);

        return ApiResponse::created('Credential stored.', new AiProviderCredentialResource($credential));
    }

    /**
     * Revoke the platform credential. Revocation is a timestamp, not a
     * delete, so the audit trail keeps the fact of the rotation.
     */
    public function revoke(AiProvider $provider, AiProviderCredential $credential): JsonResponse
    {
        if ($credential->provider_id !== $provider->getKey() || $credential->tenant_id !== null) {
            abort(404);
        }

        $credential = $this->admin->revokeCredential($credential);

        $credential->setRelation('provider', $provider);

        return ApiResponse::success('Credential revoked.', new AiProviderCredentialResource($credential));
    }

    /**
     * Test the provider's live credential: a cheap reachability check, not
     * a chat completion. The result is recorded against the provider's
     * health state — an operator-verified success closes an open circuit.
     */
    public function test(AiProvider $provider): JsonResponse
    {
        $result = $this->admin->testConnection($provider);

        $provider->refresh();

        return ApiResponse::success('', [
            'provider' => [
                'id' => $provider->id,
                'key' => $provider->key,
                'name' => $provider->name,
            ],
            'result' => $result,
            'health' => $provider->health !== null ? [
                'state' => $provider->health->state->value,
                'allows_calls' => $provider->health->allowsCalls(),
                'cooldown_until' => $provider->health->cooldown_until?->toIso8601String(),
            ] : null,
        ]);
    }
}
