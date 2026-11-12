<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Enums\AiErrorCategory;
use App\Enums\AiTask;
use App\Services\Ai\AiExecutionEngine;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Support\AiCapability;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Ai\Support\PromptRenderer;
use App\Services\Ai\Validation\JsonSchemaValidator;

/**
 * Shared machinery for every AI feature.
 *
 * A concrete feature only has to build an honest prompt from data it has already
 * verified; this base enforces the parts that must never be skipped — the master
 * switch, the per-feature and daily limits, the configured token ceiling, the
 * provider capability check, the server-side schema validation and usage
 * accounting. Because every feature funnels through {@see self::run()}, a new
 * feature cannot accidentally bypass the guard, trust an unvalidated answer or
 * forget to record its cost.
 *
 * Provider selection, retries, failover and health tracking belong to
 * {@see AiExecutionEngine}; this class owns the parts that are about the
 * *request* rather than the provider: guards, the prompt pair and the schema
 * contract, including the one bounded repair a wrong answer gets.
 */
abstract class Feature
{
    public function __construct(
        protected readonly AiExecutionEngine $engine,
        protected readonly AiGuard $guard,
        protected readonly AiUsageRecorder $usage,
        protected readonly PromptGuard $prompts,
        protected readonly JsonSchemaValidator $validator,
        protected readonly PromptRenderer $renderer,
    ) {}

    /**
     * The use case's stable key, e.g. `reply`.
     *
     * It is the name the feature is configured, limited and recorded under, so
     * it must not change once it has shipped.
     */
    abstract public function useCaseKey(): string;

    /**
     * Run one guarded completion and record its usage.
     *
     * The engine selects the provider, retries transient failures and fails
     * over when policy allows; its failures are normalized and re-thrown here
     * so the exception handler can turn them into a clear, recoverable
     * response. A successful call is accounted for by the engine, which knows
     * which provider actually served it and how many fallbacks it took.
     *
     * When a schema was requested the answer is validated here, on the server.
     * A provider that enforces the schema is a convenience; this check is the
     * guarantee. An answer that does not match is never handed to a feature,
     * and the model gets one bounded chance to repair it.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  list<AiCapability>  $capabilities  Extra provider capabilities the use case needs.
     */
    protected function run(
        string $feature,
        string $systemPrompt,
        string $userPrompt,
        ?int $tenantId,
        ?int $userId,
        ?array $schema = null,
        ?string $schemaName = null,
        array $capabilities = [],
    ): AiCompletion {
        $config = $this->guard->feature($feature);
        $this->guard->enforceDailyLimits($feature, $config, $tenantId, $userId);

        $task = AiTask::forFeatureKey($feature);

        // A stored template overrides the built-in instruction when one
        // exists; with none, the feature's own prompt serves exactly as
        // before. The user prompt is never templated: it carries business
        // data the feature assembles through the guard.
        $rendered = $this->renderer->render($task, $this->useCaseKey(), variables: ['feature' => $feature]);

        $messages = [
            AiMessage::system($rendered['system_prompt'] ?? $systemPrompt),
            AiMessage::user($userPrompt),
        ];

        $options = array_filter([
            'max_output_tokens' => isset($config['max_output_tokens']) ? (int) $config['max_output_tokens'] : null,
            'temperature' => $config['temperature'] ?? null,
            'safety_identifier' => $this->safetyIdentifier($userId),
        ], static fn (mixed $value): bool => $value !== null);

        $repairs = $schema === null ? 0 : min(1, max(0, (int) ($config['repair_attempts'] ?? 0)));

        for ($attempt = 0; $attempt <= $repairs; $attempt++) {
            $result = $this->engine->run(
                task: $task,
                feature: $feature,
                messages: $messages,
                schema: $schema,
                schemaName: (string) $schemaName,
                options: $options,
                tenantId: $tenantId,
                userId: $userId,
                capabilities: $capabilities,
            );

            $completion = $result['completion'];

            if ($schema === null) {
                return $completion;
            }

            $validation = $this->validator->validate($completion->structured, $schema);

            if ($validation->isValid()) {
                return $completion;
            }

            if ($attempt === $repairs) {
                $this->usage->recordFailure(
                    feature: $feature,
                    tenantId: $tenantId,
                    userId: $userId,
                    reason: 'ai_schema_invalid',
                    metadata: ['errors' => $validation->errors()],
                    provider: $result['provider'],
                    task: $task,
                    correlationId: $result['correlation_id'],
                    errorCategory: AiErrorCategory::InvalidOutput,
                    fallbackAttempts: $result['fallback_attempts'],
                );

                throw AiRequestFailed::invalidOutput($validation->summary());
            }

            $messages = [
                ...$messages,
                AiMessage::assistant($this->prompts->truncate((string) json_encode($completion->structured), 2000)),
                AiMessage::user($this->repairInstruction($validation->summary())),
            ];
        }

        // The loop always returns or throws; this is unreachable but keeps the
        // contract explicit rather than implied.
        throw AiRequestFailed::invalidOutput('the answer could not be validated');
    }

    /**
     * What the model is told when its answer did not match the schema.
     */
    private function repairInstruction(string $problems): string
    {
        return implode(' ', [
            'Your previous answer did not match the required schema.',
            'Problems: '.$problems,
            'Return the corrected JSON object only. Do not add commentary, and do not change the schema.',
        ]);
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
