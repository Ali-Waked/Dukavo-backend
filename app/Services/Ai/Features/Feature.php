<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Support\PromptGuard;

/**
 * Shared machinery for every AI feature.
 *
 * A concrete feature only has to build an honest prompt from data it has already
 * verified; this base enforces the parts that must never be skipped — the master
 * switch, the per-feature and daily limits, the configured token ceiling, and
 * usage accounting. Because every feature funnels through {@see self::run()}, a
 * new feature cannot accidentally bypass the guard or forget to record its cost.
 */
abstract class Feature
{
    public function __construct(
        protected readonly LanguageModel $model,
        protected readonly AiGuard $guard,
        protected readonly AiUsageRecorder $usage,
        protected readonly PromptGuard $prompts,
    ) {}

    /**
     * Run one guarded completion and record its usage.
     *
     * Provider failures are logged against the feature and re-thrown so the
     * exception handler can turn them into a clear, recoverable response; a
     * successful call is recorded before it is returned.
     *
     * @param  array<string, mixed>|null  $schema
     */
    protected function run(
        string $feature,
        string $systemPrompt,
        string $userPrompt,
        ?int $tenantId,
        ?int $userId,
        ?array $schema = null,
        ?string $schemaName = null,
    ): AiCompletion {
        $config = $this->guard->feature($feature);
        $this->guard->enforceDailyLimits($feature, $config, $tenantId, $userId);

        if (! $this->model->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $messages = [
            AiMessage::system($systemPrompt),
            AiMessage::user($userPrompt),
        ];

        $options = array_filter([
            'max_output_tokens' => isset($config['max_output_tokens']) ? (int) $config['max_output_tokens'] : null,
            'temperature' => $config['temperature'] ?? null,
            'safety_identifier' => $this->safetyIdentifier($userId),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $completion = $schema === null
                ? $this->model->complete($messages, $options)
                : $this->model->structured($messages, $schema, (string) $schemaName, $options);
        } catch (AiUnavailable|AiRequestFailed $exception) {
            $this->usage->recordFailure(
                $feature,
                $tenantId,
                $userId,
                $exception->errorCode(),
                null,
                $exception->context(),
            );

            throw $exception;
        }

        $this->usage->recordSuccess($feature, $completion, $tenantId, $userId);

        return $completion;
    }

    /**
     * A stable, non-reversible identifier for abuse attribution. Never the user
     * id or an email address in the clear.
     */
    protected function safetyIdentifier(?int $userId): ?string
    {
        return $userId === null
            ? null
            : hash('sha256', 'dukavo-user|'.$userId);
    }

    /**
     * The token/latency figures the frontend may display. Never the prompt or
     * the model's raw payload.
     *
     * @return array{model: string, input_tokens: int, output_tokens: int, total_tokens: int}
     */
    protected function usageSummary(AiCompletion $completion): array
    {
        return [
            'model' => $completion->model,
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'total_tokens' => $completion->totalTokens,
        ];
    }
}
