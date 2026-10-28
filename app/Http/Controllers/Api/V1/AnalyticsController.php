<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ProfitIntelligenceRequest;
use App\Models\Store;
use App\Services\Analytics\AnalyticsService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Executive analytics workspace.
 *
 * The endpoint reuses the profit report's range and currency rules so sales
 * figures, store scoping and timezone boundaries stay identical across both
 * screens. When a metric cannot be supported by stored data the payload says
 * so rather than returning zero as if it were measured.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function index(ProfitIntelligenceRequest $request): JsonResponse
    {
        [$from, $until] = $request->range();

        $data = $this->analytics->overview(
            $this->store($request),
            $request->currency(),
            CarbonImmutable::parse($from->toDateString()),
            CarbonImmutable::parse($until->toDateString()),
            $request->productLimit(),
        );

        return ApiResponse::success('Analytics overview.', $data);
    }

    public function metrics(ProfitIntelligenceRequest $request): JsonResponse
    {
        [$from, $until] = $request->range();

        $data = $this->analytics->overview(
            $this->store($request),
            $request->currency(),
            CarbonImmutable::parse($from->toDateString()),
            CarbonImmutable::parse($until->toDateString()),
            $request->productLimit(),
        );

        return ApiResponse::success('Analytics metrics.', [
            'context' => $data['context'],
            'range' => $data['range'],
            'comparison' => $data['comparison'],
            'currency' => $data['currency'],
            'definitions' => $data['definitions'],
            'kpis' => $data['kpis'],
            'limitations' => $data['limitations'],
            'methodology' => $data['methodology'],
        ]);
    }

    private function store(ProfitIntelligenceRequest $request): ?Store
    {
        return $request->filled('store_id')
            ? Store::query()->findOrFail($request->integer('store_id'))
            : null;
    }
}
