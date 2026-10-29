<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Services\Ai\Data\AiCompletion;

/**
 * Writes the usage rows that back the daily limits.
 *
 * Recording is best-effort accounting, never part of the answer contract: a
 * failure here must not turn a successful AI call into an error for the user,
 * so writes are isolated and reported rather than thrown.
 *
 * Only counts and identifiers are written. No prompt, no answer, no customer
 * data and no credential ever reaches this table.
 */
final class AiUsageRecorder
{
    public function recordSuccess(
        string $feature,
        AiCompletion $completion,
        ?int $tenantId,
        ?int $userId,
        ?string $provider = null,
    ): void {
        $this->write([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'feature' => $feature,
            'status' => 'success',
            'provider' => $provider,
            'model' => $completion->model,
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'total_tokens' => $completion->totalTokens,
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
    ): void {
        $this->write([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'feature' => $feature,
            'status' => 'failed',
            'provider' => $provider,
            'model' => $model,
            'metadata' => ['reason' => $reason] + $metadata,
        ]);
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
