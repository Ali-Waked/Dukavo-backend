<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Ai\RegisterAiProviderRequest;
use App\Http\Requests\Platform\Ai\UpdateAiProviderRequest;
use App\Http\Resources\Ai\AiProviderResource;
use App\Models\AiProvider;
use App\Services\Ai\Admin\AiAdminService;
use App\Services\Ai\Providers\ProviderCatalog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform administration of the provider catalogue.
 *
 * Providers are registered from the driver catalogue rather than invented
 * freely, so a row always names something the factory can build. The row
 * carries identity and posture — enabled, priority, notes — and never a
 * credential (see the credential controller).
 */
final class AiProviderController extends Controller
{
    public function __construct(private readonly AiAdminService $admin) {}

    public function index(): AnonymousResourceCollection
    {
        $providers = AiProvider::query()
            ->with(['platformCredential', 'health'])
            ->withCount(['models', 'routes', 'credentials'])
            ->orderBy('priority')
            ->orderBy('name')
            ->paginate(50);

        return AiProviderResource::collection($providers);
    }

    /**
     * The driver catalogue, marked with which entries already have a row —
     * "add provider" in the dashboard is a choice from this list, not a text
     * field.
     */
    public function catalog(): JsonResponse
    {
        $registered = AiProvider::query()
            ->pluck('key')
            ->map(fn ($key): string => (string) $key)
            ->all();

        return ApiResponse::success('', [
            'entries' => array_map(
                static fn (array $entry): array => $entry + ['registered' => in_array($entry['key'], $registered, true)],
                ProviderCatalog::entries(),
            ),
        ]);
    }

    public function store(RegisterAiProviderRequest $request): JsonResponse
    {
        $provider = $this->admin->registerCatalogProvider(
            (string) $request->validated('key'),
            (array) $request->safe()->except('key'),
        );

        $provider->load(['platformCredential', 'health'])
            ->loadCount(['models', 'routes', 'credentials']);

        return ApiResponse::created('Provider registered.', new AiProviderResource($provider));
    }

    public function show(AiProvider $provider): AiProviderResource
    {
        $provider->load(['platformCredential', 'health', 'models'])
            ->loadCount(['routes', 'credentials']);

        return new AiProviderResource($provider);
    }

    public function update(UpdateAiProviderRequest $request, AiProvider $provider): JsonResponse
    {
        $provider->fill($request->safe()->all())->save();

        $provider->refresh()->load(['platformCredential', 'health'])
            ->loadCount(['models', 'routes', 'credentials']);

        return ApiResponse::success('Provider updated.', new AiProviderResource($provider));
    }

    /**
     * Removing a provider row would orphan credentials, routing and health
     * state that all reference it. Disabling achieves the operator's goal
     * without that damage, so deletion is refused.
     */
    public function destroy(AiProvider $provider): JsonResponse
    {
        abort(405, 'Providers cannot be deleted; disable the provider instead.');
    }
}
