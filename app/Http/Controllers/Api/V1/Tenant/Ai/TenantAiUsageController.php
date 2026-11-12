<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\Tenant\TenantAiUsageAnalytics;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The workspace's AI consumption.
 *
 * Reads the shared usage ledger through the tenant-scoped analytics, so a
 * merchant sees only their own workspace's requests, tokens and estimated
 * costs — the platform's own use and other workspaces never appear.
 */
final class TenantAiUsageController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantAiUsageAnalytics $analytics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $days = (int) min(max($request->integer('days', 30), 1), 365);
        $since = Carbon::now()->subDays($days)->startOfDay();

        return ApiResponse::success('', $this->analytics->summary($this->tenants->required(), $since), meta: [
            'days' => $days,
            'since' => $since->toIso8601String(),
            'currency' => 'USD',
            'costs_are_estimates' => true,
        ]);
    }
}
