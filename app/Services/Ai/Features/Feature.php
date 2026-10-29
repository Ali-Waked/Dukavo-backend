<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\AiCapability;
use App\Services\Ai\Support\PromptGuard;
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
 */
abstract class Feature
{
    public function __construct(
        protected readonly ProviderRegistry $providers,
        protected readonly AiGuard $guard,
        protected readonly AiUsageRecorder $usage,
        protected readonly PromptGuard $prompts,
        protected readonly JsonSchemaValidator $validator,
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
     * Provider failures are logged against the feature and re-thrown so the
     * exception handler can turn them into a clear, recoverable response; a
     * successful call is recorded before it is returned.
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

        $required = [AiCapability::TextCompletion, ...$capabilities];

        if ($schema !== null) {
            $required[] = AiCapability::StructuredOutput;
        }

        $provider = $this->providers->for(...$required);

        $messages = [
            AiMessage::system($systemPrompt),
            AiMessage::user($userPrompt),
        ];

        $options = array_filter([
            'max_output_tokens' => isset($config['max_output_tokens']) ? (int) $config['max_output_tokens'] : null,
            'temperature' => $config['temperature'] ?? null,
            'safety_identifier' => $this->safetyIdentifier($userId),
        ], static fn (mixed $value): bool => $value !== null);

        $repairs = $schema === null ? 0 : min(1, max(0, (int) ($config['repair_attempts'] ?? 0)));

        for ($attempt = 0; $attempt <= $repairs; $attempt++) {
            $completion = $this->call($provider, $messages, $options, $schema, $schemaName, $feature, $tenantId, $userId);

            if ($schema === null) {
                return $completion;
            }

            $result = $this->validator->validate($completion->structured, $schema);

            if ($result->isValid()) {
                return $completion;
            }

            if ($attempt === $repairs) {
                $this->usage->recordFailure(
                    feature: $feature,
                    tenantId: $tenantId,
                    userId: $userId,
                    reason: 'ai_schema_invalid',
                    metadata: ['errors' => $result->errors()],
                    provider: $provider->provider(),
                );

                throw AiRequestFailed::invalidOutput($result->summary());
            }

            $messages = [
                ...$messages,
                AiMessage::assistant($this->prompts->truncate((string) json_encode($completion->structured), 2000)),
                AiMessage::user($this->repairInstruction($result->summary())),
            ];
        }

        // The loop always returns or throws; this is unreachable but keeps the
        // contract explicit rather than implied.
        throw AiRequestFailed::invalidOutput('the answer could not be validated');
    }

    /**
     * One provider call, accounted for whether it succeeds or not.
     *
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $schema
     */
    private function call(
        AiProvider $provider,
        array $messages,
        array $options,
        ?array $schema,
        ?string $schemaName,
        string $feature,
        ?int $tenantId,
        ?int $userId,
    ): AiCompletion {
        try {
            $completion = $schema === null
                ? $provider->complete($messages, $options)
                : $provider->structured($messages, $schema, (string) $schemaName, $options);
        } catch (AiUnavailable|AiRequestFailed $exception) {
            $this->usage->recordFailure(
                feature: $feature,
                tenantId: $tenantId,
                userId: $userId,
                reason: $exception->errorCode(),
                metadata: $exception->context(),
                provider: $provider->provider(),
            );

            throw $exception;
        }

        $this->usage->recordSuccess($feature, $completion, $tenantId, $userId, $provider->provider());

        return $completion;
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
