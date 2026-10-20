<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\PlatformInsightsRequest;
use App\Services\Ai\Features\DashboardInsights;
use App\Services\Reporting\PlatformDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * AI briefing over the platform overview's own aggregates.
 *
 * The numbers are the same ones the operator dashboard displays, computed across
 * Dukavo's tables; no tenant is resolved, which is inherent to a platform view.
 */
class PlatformInsightsController extends Controller
{
    public function __construct(private readonly DashboardInsights $insights) {}

    public function index(PlatformInsightsRequest $request): JsonResponse
    {
        $range = $request->validated('range') ?? PlatformDashboardService::DEFAULT_RANGE;

        $result = $this->insights->forPlatform($range, (int) $request->user()->getKey());

        return ApiResponse::success('Insights ready.', $result);
    }
}
