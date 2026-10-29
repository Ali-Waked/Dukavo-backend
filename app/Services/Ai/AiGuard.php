<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Services\Ai\Exceptions\AiUnavailable;
use Illuminate\Support\Carbon;

/**
 * The gate every AI feature passes through before a single token is spent.
 *
 * It answers two questions: is this feature switched on for this installation,
 * and has the caller (or their workspace) already used today's allowance? Both
 * are cheap database/config checks, so an over-quota or disabled feature costs
 * nothing and degrades to a clear "unavailable" instead of a provider charge.
 *
 * Both a call count and a token volume are capped. The call count stops a
 * caller from making a thousand small requests; the token volume stops one
 * large request from being expensive. A provider call that failed still
 * counts: it consumed the budget even though it produced nothing.
 */
final class AiGuard
{
    /**
     * Resolve a feature's configuration, or refuse when it is off.
     *
     * @return array{enabled?: bool, max_output_tokens?: int, temperature?: float|null, daily_per_user?: int, daily_per_tenant?: int, daily_tokens_per_user?: int, daily_tokens_per_tenant?: int, repair_attempts?: int}
     */
    public function feature(string $feature): array
    {
        $config = config("ai.features.{$feature}");

        if (! is_array($config) || ! ($config['enabled'] ?? false) || ! $this->enabled()) {
            throw AiUnavailable::disabled($feature);
        }

        return $config;
    }

    public function enabled(): bool
    {
        return (bool) config('ai.enabled');
    }

    /**
     * @param  array{daily_per_user?: int, daily_per_tenant?: int, daily_tokens_per_user?: int, daily_tokens_per_tenant?: int}  $featureConfig
     */
    public function enforceDailyLimits(string $feature, array $featureConfig, ?int $tenantId, ?int $userId): void
    {
        if ($userId !== null) {
            $this->assertUnderLimit(
                $this->usedToday($feature, $tenantId, $userId),
                (int) ($featureConfig['daily_per_user'] ?? 0),
                'user',
            );

            $this->assertUnderLimit(
                $this->tokensUsedToday($feature, $tenantId, $userId),
                (int) ($featureConfig['daily_tokens_per_user'] ?? 0),
                'user',
                'tokens',
            );
        }

        if ($tenantId !== null) {
            $this->assertUnderLimit(
                $this->usedToday($feature, $tenantId, null),
                (int) ($featureConfig['daily_per_tenant'] ?? 0),
                'tenant',
            );

            $this->assertUnderLimit(
                $this->tokensUsedToday($feature, $tenantId, null),
                (int) ($featureConfig['daily_tokens_per_tenant'] ?? 0),
                'tenant',
                'tokens',
            );
        }
    }

    private function assertUnderLimit(int $used, int $cap, string $scope, string $unit = 'requests'): void
    {
        if ($cap > 0 && $used >= $cap) {
            throw AiUnavailable::rateLimited($scope, $unit);
        }
    }

    /**
     * How many provider calls were made for this feature today.
     *
     * Failed attempts are counted: they were billed.
     */
    private function usedToday(string $feature, ?int $tenantId, ?int $userId): int
    {
        return AiUsage::query()
            ->where('feature', $feature)
            ->where('created_at', '>=', $this->startOfDay())
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->count();
    }

    /**
     * How many tokens were spent for this feature today.
     */
    private function tokensUsedToday(string $feature, ?int $tenantId, ?int $userId): int
    {
        return (int) AiUsage::query()
            ->where('feature', $feature)
            ->where('created_at', '>=', $this->startOfDay())
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->sum('total_tokens');
    }

    private function startOfDay(): Carbon
    {
        return now()->startOfDay();
    }
}
