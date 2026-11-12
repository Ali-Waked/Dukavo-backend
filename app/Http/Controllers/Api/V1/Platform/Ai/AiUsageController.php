<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\Admin\AiUsageAnalytics;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Usage and cost reporting for the AI administration.
 *
 * Everything here is an estimate over the usage ledger: tokens and costs
 * are what the providers reported, not a billing fact, and the payload
 * says so — tenant billing never derives from these numbers.
 */
final class AiUsageController extends Controller
{
    public function __construct(private readonly AiUsageAnalytics $analytics) {}

    public function index(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $since = Carbon::now()->subDays($days)->startOfDay();

        return ApiResponse::success('', [
            'totals' => $this->analytics->totals($since),
            'by_provider' => $this->analytics->byProvider($since),
            'by_task' => $this->analytics->byTask($since),
            'daily' => $this->analytics->daily($since),
            'by_tenant' => $this->analytics->byTenant($since),
            'failure_categories' => $this->analytics->failureCategories($since),
        ], meta: [
            'days' => $days,
            'since' => $since->toIso8601String(),
            'currency' => 'USD',
            'costs_are_estimates' => true,
        ]);
    }

    private function days(Request $request): int
    {
        return (int) min(max($request->integer('days', 30), 1), 365);
    }
}
