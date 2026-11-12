<?php

declare(strict_types=1);

namespace App\Services\Ai\Tenant;

use App\Models\AiUsage;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The workspace's own AI consumption, from the shared usage ledger.
 *
 * Scoped to one tenant by construction: every query filters on the
 * tenant's id, so the dashboard a merchant reads can never see another
 * workspace's numbers. Costs are estimates over what providers reported,
 * never a billing fact — the payload says so.
 */
final class TenantAiUsageAnalytics
{
    /**
     * @return array{
     *     today_requests: int,
     *     today_tokens: int,
     *     requests: int,
     *     failures: int,
     *     tokens: int,
     *     estimated_cost_usd: float,
     *     by_feature: list<array{feature: string, requests: int, failures: int, tokens: int}>,
     *     daily: list<array{date: string, requests: int, failures: int}>,
     * }
     */
    public function summary(Tenant $tenant, ?Carbon $since = null): array
    {
        $since ??= now()->subDays(30)->startOfDay();
        $today = now()->startOfDay();

        $tenantId = $tenant->getKey();

        $todayRequests = AiUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $today)
            ->toBase()
            ->selectRaw('COUNT(DISTINCT COALESCE(correlation_id, CAST(id AS CHAR))) as requests')
            ->value('requests');

        $base = AiUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since);

        $requests = AiUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->toBase()
            ->selectRaw('COUNT(DISTINCT COALESCE(correlation_id, CAST(id AS CHAR))) as requests')
            ->value('requests');

        return [
            'today_requests' => (int) $todayRequests,
            'today_tokens' => (int) AiUsage::query()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $today)
                ->sum('total_tokens'),
            'requests' => (int) $requests,
            'failures' => (int) (clone $base)->where('status', 'failed')->count(),
            'tokens' => (int) (clone $base)->sum('total_tokens'),
            'estimated_cost_usd' => round((float) (clone $base)->sum('estimated_cost'), 4),
            'by_feature' => $this->byFeature($tenant, $since),
            'daily' => $this->daily($tenant, $since),
        ];
    }

    /**
     * @return list<array{feature: string, requests: int, failures: int, tokens: int}>
     */
    private function byFeature(Tenant $tenant, Carbon $since): array
    {
        return AiUsage::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('created_at', '>=', $since)
            ->whereNotNull('feature')
            ->groupBy('feature')
            ->orderByDesc('requests')
            ->get([
                'feature',
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failures'),
                DB::raw('SUM(total_tokens) as tokens'),
            ])
            ->map(fn ($row): array => [
                'feature' => (string) $row->feature,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'tokens' => (int) $row->tokens,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{date: string, requests: int, failures: int}>
     */
    private function daily(Tenant $tenant, Carbon $since): array
    {
        return AiUsage::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('created_at', '>=', $since)
            ->groupBy('date')
            ->orderBy('date')
            ->get([
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as attempts'),
                DB::raw('SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failures'),
            ])
            ->map(fn ($row): array => [
                'date' => (string) $row->date,
                'requests' => (int) $row->attempts,
                'failures' => (int) $row->failures,
            ])
            ->values()
            ->all();
    }
}
