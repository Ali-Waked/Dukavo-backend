<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Settings\ToggleFeatureFlagRequest;
use App\Services\Configuration\FeatureCatalogue;
use App\Services\Configuration\FeatureFlagService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Platform availability flags.
 *
 * A flag withdraws a capability for the whole platform. It is not a merchant
 * entitlement: no plan or subscription check exists behind it, and enabling it
 * does not grant a merchant anything its permissions and configuration do not
 * already allow.
 */
class PlatformFeatureFlagController extends Controller
{
    public function __construct(private readonly FeatureFlagService $features) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('', [
            'features' => $this->features->all(),
        ]);
    }

    public function update(ToggleFeatureFlagRequest $request, string $flag): JsonResponse
    {
        if (! FeatureCatalogue::has($flag)) {
            abort(404, 'Unknown platform feature flag.');
        }

        $this->features->set($flag, (bool) $request->boolean('enabled'), $request->user());

        return ApiResponse::success('Feature updated.', [
            'features' => $this->features->all(),
        ]);
    }
}
