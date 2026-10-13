<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Reporting\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard aggregates.
 *
 * Every figure is computed in SQL by {@see DashboardService} rather than by
 * loading rows into PHP, and every query inherits the active tenant scope, so
 * there is no path by which these numbers can include another merchant's data.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);

        return response()->json([
            'success' => true,
            'data' => [
                'context' => $this->dashboard->context(),
                'summary' => $this->dashboard->summary($store),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard->summary($this->store($request)),
        ]);
    }

    /**
     * Daily revenue for a chart.
     */
    public function revenue(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard->revenueSeries(
                min($request->integer('days', 30), 365),
                $this->store($request),
            ),
        ]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard->topProducts(
                min($request->integer('limit', 5), 25),
                $this->store($request),
            ),
        ]);
    }

    public function deadStock(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard->deadStock(min($request->integer('limit', 5), 25)),
        ]);
    }

    /**
     * A store id is optional everywhere; when absent the figures cover the whole
     * tenant. The lookup is tenant scoped, so a foreign id is a 404.
     */
    private function store(Request $request): ?Store
    {
        return $request->filled('store_id')
            ? Store::query()->findOrFail($request->integer('store_id'))
            : null;
    }
}
