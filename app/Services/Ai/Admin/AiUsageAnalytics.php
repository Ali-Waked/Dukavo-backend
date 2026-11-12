<?php

declare(strict_types=1);

namespace App\Services\Ai\Admin;

use App\Models\AiActivityLog;
use App\Models\AiUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AI usage analytics for the platform dashboard.
 *
 * Aggregates the append-only usage ledger into the figures operators need:
 * totals by period, per provider, per task and per tenant, plus failure and
 * fallback rates from the activity log. Estimates are labelled as estimates
 * everywhere they appear — they never feed tenant billing.
 */
final class AiUsageAnalytics
{
    /**
     * The headline figures for the overview screen.
     *
     * Rows sharing a correlation id are one logical request, so the count is
     * of distinct requests (retries and failovers each already have their own
     * row, which the token and cost totals deliberately include — every
     * attempt was billed). Legacy rows without a correlation id each count
     * as their own request.
     *
     * @return array{
     *     requests: int,
     *     failures: int,
     *     tokens: int,
     *     estimated_cost_usd: float,
     *     fallback_requests: int,
     *     cache_hits: int,
     *     unique_tenants: int,
     * }
     */
    public function totals(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30)->startOfDay();

        $base = AiUsage::query()->where('created_at', '>=', $since);

        $requests = AiUsage::query()
            ->where('created_at', '>=', $since)
            ->toBase()
            ->selectRaw('COUNT(DISTINCT COALESCE(correlation_id, CAST(id AS CHAR))) as requests')
            ->value('requests');

        return [
            'requests' => (int) $requests,
            'failures' => (int) (clone $base)->where('status', 'failed')->count(),
            'tokens' => (int) (clone $base)->sum('total_tokens'),
            'estimated_cost_usd' => round((float) (clone $base)->sum('estimated_cost'), 4),
            'fallback_requests' => (int) (clone $base)->where('fallback_attempts', '>', 0)->count(),
            'cache_hits' => (int) (clone $base)->where('cache_hit', true)->count(),
            'unique_tenants' => (int) (clone $base)->whereNotNull('tenant_id')->distinct('tenant_id')->count('tenant_id'),
        ];
    }

    /**
     * Usage grouped by provider.
     *
     * @return list<array{provider: string, requests: int, failures: int, tokens: int, estimated_cost_usd: float}>
     */
    public function byProvider(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30)->startOfDay();

        return AiUsage::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('provider')
            ->groupBy('provider')
            ->orderByDesc('requests')
            ->get([
                'provider',
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failures'),
                DB::raw('SUM(total_tokens) as tokens'),
                DB::raw('COALESCE(SUM(estimated_cost), 0) as estimated_cost_usd'),
            ])
            ->map(fn ($row): array => [
                'provider' => (string) $row->provider,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => round((float) $row->estimated_cost_usd, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * Usage grouped by task.
     *
     * @return list<array{task: string, requests: int, failures: int, tokens: int, estimated_cost_usd: float}>
     */
    public function byTask(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30)->startOfDay();

        return AiUsage::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('task')
            ->groupBy('task')
            ->get([
                'task',
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failures'),
                DB::raw('SUM(total_tokens) as tokens'),
                DB::raw('COALESCE(SUM(estimated_cost), 0) as estimated_cost_usd'),
            ])
            ->map(fn ($row): array => [
                'task' => (string) $row->task,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => round((float) $row->estimated_cost_usd, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * Daily request and cost series for the chart.
     *
     * @return list<array{date: string, requests: int, failures: int, estimated_cost_usd: float}>
     */
    public function daily(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30)->startOfDay();

        return AiUsage::query()
            ->where('created_at', '>=', $since)
            ->groupBy('date')
            ->orderBy('date')
            ->get([
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as attempts'),
                DB::raw('SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failures'),
                DB::raw('COALESCE(SUM(estimated_cost), 0) as estimated_cost_usd'),
            ])
            ->map(fn ($row): array => [
                'date' => (string) $row->date,
                'requests' => (int) $row->attempts,
                'failures' => (int) $row->failures,
                'estimated_cost_usd' => round((float) $row->estimated_cost_usd, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * Per-tenant consumption, heaviest first.
     *
     * The join is only for the workspace's display name; the numbers come
     * from the ledger. Rows without a tenant (platform marketing use) are
     * excluded — this is per-workspace reporting.
     *
     * @return list<array{tenant_id: int, tenant_name: string, requests: int, tokens: int, estimated_cost_usd: float}>
     */
    public function byTenant(?Carbon $since = null, int $limit = 10): array
    {
        $since ??= now()->subDays(30)->startOfDay();

        return AiUsage::query()
            ->where('ai_usage.created_at', '>=', $since)
            ->whereNotNull('ai_usage.tenant_id')
            ->join('tenants', 'tenants.id', '=', 'ai_usage.tenant_id')
            ->groupBy('ai_usage.tenant_id', 'tenants.name')
            ->orderByDesc('requests')
            ->limit($limit)
            ->get([
                'ai_usage.tenant_id',
                'tenants.name as tenant_name',
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(ai_usage.total_tokens) as tokens'),
                DB::raw('COALESCE(SUM(ai_usage.estimated_cost), 0) as estimated_cost_usd'),
            ])
            ->map(fn ($row): array => [
                'tenant_id' => (int) $row->tenant_id,
                'tenant_name' => (string) $row->tenant_name,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => round((float) $row->estimated_cost_usd, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * Failure categories from the activity log, most frequent first.
     *
     * @return list<array{error_category: string, count: int}>
     */
    public function failureCategories(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30)->startOfDay();

        return AiActivityLog::query()
            ->where('created_at', '>=', $since)
            ->where('status', 'failed')
            ->whereNotNull('error_category')
            ->groupBy('error_category')
            ->orderByDesc('count')
            ->get(['error_category', DB::raw('COUNT(*) as count')])
            ->map(fn ($row): array => [
                'error_category' => (string) $row->error_category,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }
}
