<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiErrorCategory;
use App\Enums\AiTask;
use App\Models\AiUsage;
use App\Services\Ai\Data\AiCompletion;

/**
 * Writes the usage rows that back the daily limits and the cost reports.
 *
 * Recording is best-effort accounting, never part of the answer contract: a
 * failure here must not turn a successful AI call into an error for the user,
 * so writes are isolated and reported rather than thrown.
 *
 * Only counts and identifiers are written. No prompt, no answer, no customer
 * data and no credential ever reaches this table.
 *
 * Every call — successful, failed, retried or served by a fallback provider —
 * is a row: retries and failovers cannot dodge accounting, because the
 * execution engine writes one row per provider attempt, tied together by the
 * correlation id.
 */
final class AiUsageRecorder
{
    public function recordSuccess(
        string $feature,
        AiCompletion $completion,
        ?int $tenantId,
        ?int $userId,
        ?string $provider = null,
        ?AiTask $task = null,
        ?string $correlationId = null,
        int $fallbackAttempts = 0,
        bool $cacheHit = false,
    ): void {
        $this->write([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'feature' => $feature,
            'task' => ($task ?? AiTask::forFeatureKey($feature))->value,
            'correlation_id' => $correlationId,
            'provider' => $provider ?? ($completion->provider !== '' ? $completion->provider : null),
            'model' => $completion->model,
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'total_tokens' => $completion->totalTokens,
            'fallback_attempts' => $fallbackAttempts,
            'estimated_cost' => $this->estimateCost($completion),
            'cache_hit' => $cacheHit,
            'latency_ms' => $completion->latencyMs,
            'provider_response_id' => $completion->providerResponseId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordFailure(
        string $feature,
        ?int $tenantId,
        ?int $userId,
        string $reason,
        ?string $model = null,
        array $metadata = [],
        ?string $provider = null,
        ?AiTask $task = null,
        ?string $correlationId = null,
        ?AiErrorCategory $errorCategory = null,
        int $fallbackAttempts = 0,
    ): void {
        $this->write([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'feature' => $feature,
            'task' => ($task ?? AiTask::forFeatureKey($feature))->value,
            'correlation_id' => $correlationId,
            'status' => 'failed',
            'error_category' => $errorCategory?->value,
            'provider' => $provider,
            'model' => $model,
            'fallback_attempts' => $fallbackAttempts,
            'metadata' => ['reason' => $reason] + $metadata,
        ]);
    }

    /**
     * A conservative USD estimate from the configured price list.
     *
     * Prices are per million tokens. An unlisted model records no estimate
     * rather than a fabricated zero, so a report never claims a cost it cannot
     * justify. A free provider records no estimate either: "free" is a rate
     * limit away from "billed", never a licence for unlimited calls.
     */
    private function estimateCost(AiCompletion $completion): ?float
    {
        if ($completion->model === '') {
            return null;
        }

        // Model ids may contain dots (`grok-4.7`), so the price list is read as
        // an array and indexed literally. A dotted config path would look for
        // `ai.pricing.grok-4` -> `7` and silently miss the entry.
        $pricing = config('ai.pricing');

        if (! is_array($pricing) || ! is_array($pricing[$completion->model] ?? null)) {
            return null;
        }

        $pricing = $pricing[$completion->model];

        $input = (float) ($pricing['input'] ?? 0);
        $output = (float) ($pricing['output'] ?? 0);

        return round((($completion->inputTokens * $input) + ($completion->outputTokens * $output)) / 1_000_000, 6);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        try {
            AiUsage::query()->create($attributes + ['created_at' => now()]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
