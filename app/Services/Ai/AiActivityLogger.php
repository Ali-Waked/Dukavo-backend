<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiActivityLog;

/**
 * Writes the per-attempt activity rows for the AI admin dashboard.
 *
 * Every provider attempt — the successful hop and every abandoned one — is a
 * row tied together by the correlation id. Recording is best-effort
 * operational telemetry, never part of the answer contract: a failure to
 * write must not turn a successful AI call into an error for the user, so
 * writes are isolated and reported rather than thrown.
 *
 * Only metadata travels here. No prompt, no answer, no customer content and
 * no credential may ever reach this table — the caller passes counts,
 * identifiers and timings only.
 */
final class AiActivityLogger
{
    /**
     * Record one attempt.
     *
     * @param  array{
     *     correlation_id: string,
     *     tenant_id?: int|null,
     *     user_id?: int|null,
     *     task: string,
     *     feature?: string|null,
     *     provider: string,
     *     model?: string|null,
     *     status: string,
     *     error_category?: string|null,
     *     http_status?: int|null,
     *     attempt?: int,
     *     fallback_attempts?: int,
     *     duration_ms?: int,
     *     input_tokens?: int,
     *     output_tokens?: int,
     *     cache_hit?: bool,
     *     metadata?: array<string, mixed>|null,
     * }  $attributes
     */
    public function record(array $attributes): void
    {
        try {
            AiActivityLog::query()->create($attributes + [
                'attempt' => 1,
                'fallback_attempts' => 0,
                'duration_ms' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cache_hit' => false,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
