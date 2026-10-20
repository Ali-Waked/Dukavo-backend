<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\TenantInsightsRequest;
use App\Models\Store;
use App\Services\Ai\Features\DashboardInsights;
use App\Services\Reporting\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * AI briefing over the tenant dashboard's own aggregates.
 *
 * The figures come from {@see DashboardService} under the
 * active tenant scope, so the briefing can never describe another merchant's
 * data. The optional store lookup is tenant scoped as well.
 */
class InsightsController extends Controller
{
    public function __construct(private readonly DashboardInsights $insights) {}

    public function tenant(TenantInsightsRequest $request): JsonResponse
    {
        $store = $request->filled('store_id')
            ? Store::query()->findOrFail($request->integer('store_id'))
            : null;

        $result = $this->insights->forTenant($store, (int) $request->user()->getKey());

        return ApiResponse::success('Insights ready.', $result);
    }
}
