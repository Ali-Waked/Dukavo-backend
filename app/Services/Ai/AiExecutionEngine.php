<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiErrorCategory;
use App\Enums\AiTask;
use App\Models\AiProvider;
use App\Models\Tenant;
use App\Services\Ai\Contracts\AiProvider as AiProviderContract;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Ai\Providers\CircuitBreaker;
use App\Services\Ai\Providers\ConfiguredAiProviderFactory;
use App\Services\Ai\Providers\CredentialSource;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Providers\TaskRouter;
use App\Services\Ai\Support\AiCapability;
use Throwable;

/**
 * Executes one AI request against the routing table, with fallback.
 *
 * This is the single place a provider call is made. It resolves the chain for
 * the task, walks it in order, and enforces the failure policy on each hop:
 * bounded same-provider retries with capped exponential backoff (never for
 * validation, authentication, content-policy or output failures), provider
 * cooldown on 429 via Retry-After, circuit-breaker checks before every hop,
 * and a refusal to fail over when the provider declined the content on
 * safety grounds.
 *
 * Two chain sources serve a request. When the provider catalog has been
 * seeded in the database, routing is database-driven ({@see TaskRouter}): task
 * routes, credentials and health are managed from the platform dashboard.
 * When it has not — an installation that never touched the AI administration
 * — the legacy configuration chain ({@see ProviderRegistry}) is used exactly
 * as before, so existing deployments and tests keep working untouched.
 *
 * Every attempt — successful or not — is written to the activity log and the
 * usage ledger, tied together by a correlation id. Usage accounting cannot be
 * bypassed by retries or fallback: each hop records its own row.
 */
final class AiExecutionEngine
{
    /**
     * The most same-provider retries a hop may make, whatever the caller asked.
     */
    private const int MAX_HOP_RETRIES = 3;

    /**
     * The longest a single backoff may sleep, in milliseconds. A failover is
     * worth more than a long sleep once a provider is misbehaving.
     */
    private const int MAX_BACKOFF_MS = 4000;

    /**
     * A Retry-After at least this long ends the hop rather than sleeping it
     * out: honouring the provider's own hint means moving on.
     */
    private const int LONG_RETRY_AFTER_SECONDS = 5;

    public function __construct(
        private readonly TaskRouter $router,
        private readonly CredentialSource $credentials,
        private readonly AiProviderFactory $factory,
        private readonly ProviderRegistry $registry,
        private readonly CircuitBreaker $breaker,
        private readonly AiUsageRecorder $usage,
        private readonly AiActivityLogger $activity,
        private readonly AiSettings $settings,
    ) {}

    /**
     * Run one request to completion or to a final, normalized failure.
     *
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>|null  $schema
     * @param  list<AiCapability>  $capabilities
     * @param  array{max_output_tokens?: int, temperature?: float|null, safety_identifier?: string|null, timeout_seconds?: int, max_retries?: int}  $options
     * @return array{completion: AiCompletion, provider: string, model: string, fallback_attempts: int, correlation_id: string}
     *
     * @throws AiUnavailable|AiRequestFailed
     */
    public function run(
        AiTask $task,
        string $feature,
        array $messages,
        ?array $schema,
        string $schemaName,
        array $options,
        ?int $tenantId,
        ?int $userId,
        array $capabilities = [],
    ): array {
        $required = [AiCapability::TextCompletion, ...$capabilities];

        if ($schema !== null) {
            $required[] = AiCapability::StructuredOutput;
        }

        $hops = $this->catalogued()
            ? $this->catalogHops($task, $tenantId, $required)
            : $this->configuredHops($required);

        if (! (bool) config('ai.fallback.enabled', true)) {
            // The operator has chosen to bound spend to one provider. The
            // breaker still decides whether that provider may be called, but
            // the chain never advances to another one.
            $hops = array_slice($hops, 0, 1);
        }

        if ($hops === []) {
            throw AiUnavailable::notConfigured();
        }

        return $this->walk(
            hops: $hops,
            task: $task,
            feature: $feature,
            messages: $messages,
            schema: $schema,
            schemaName: $schemaName,
            options: $options,
            tenantId: $tenantId,
            userId: $userId,
        );
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  list<array{adapter: AiProviderContract, row: ?AiProvider, model: ?string}>  $hops
     * @param  array<string, mixed>|null  $schema
     * @param  array{max_output_tokens?: int, temperature?: float|null, safety_identifier?: string|null, timeout_seconds?: int, max_retries?: int}  $options
     * @return array{completion: AiCompletion, provider: string, model: string, fallback_attempts: int, correlation_id: string}
     *
     * @throws AiUnavailable|AiRequestFailed
     */
    private function walk(
        array $hops,
        AiTask $task,
        string $feature,
        array $messages,
        ?array $schema,
        string $schemaName,
        array $options,
        ?int $tenantId,
        ?int $userId,
    ): array {
        $correlationId = $this->correlationId();
        $fallbackAttempts = 0;
        $lastFailure = null;

        foreach ($hops as $hop) {
            $adapter = $hop['adapter'];
            $row = $hop['row'];

            if ($row !== null && ! $this->breaker->allows($row)) {
                $fallbackAttempts++;

                continue;
            }

            $maxRetries = $this->maxRetries($options);
            $hopOptions = $options;

            if (is_string($hop['model']) && $hop['model'] !== '') {
                $hopOptions['model'] = $hop['model'];
            }

            for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
                $startedAt = hrtime(true);

                try {
                    $completion = $schema === null
                        ? $adapter->complete($messages, $hopOptions)
                        : $adapter->structured($messages, $schema, $schemaName, $hopOptions);

                    $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                    $completion = $completion->withProvider($adapter->provider());

                    if ($row !== null) {
                        $this->breaker->recordSuccess($row, $latencyMs);
                    }

                    $this->activity->record([
                        'correlation_id' => $correlationId,
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'task' => $task->value,
                        'feature' => $feature,
                        'provider' => $adapter->provider(),
                        'model' => $completion->model,
                        'status' => 'success',
                        'attempt' => $attempt,
                        'fallback_attempts' => $fallbackAttempts,
                        'duration_ms' => $latencyMs,
                        'input_tokens' => $completion->inputTokens,
                        'output_tokens' => $completion->outputTokens,
                    ]);

                    $this->usage->recordSuccess(
                        feature: $feature,
                        completion: $completion,
                        tenantId: $tenantId,
                        userId: $userId,
                        provider: $adapter->provider(),
                        task: $task,
                        correlationId: $correlationId,
                        fallbackAttempts: $fallbackAttempts,
                    );

                    return [
                        'completion' => $completion,
                        'provider' => $adapter->provider(),
                        'model' => $completion->model,
                        'fallback_attempts' => $fallbackAttempts,
                        'correlation_id' => $correlationId,
                    ];
                } catch (AiUnavailable|AiRequestFailed $exception) {
                    $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                    $category = $exception->errorCategory();

                    if ($row !== null) {
                        $this->breaker->recordFailure($row, $category, $exception->errorCode());
                    }

                    $this->activity->record([
                        'correlation_id' => $correlationId,
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'task' => $task->value,
                        'feature' => $feature,
                        'provider' => $adapter->provider(),
                        'status' => 'failed',
                        'error_category' => $category->value,
                        'attempt' => $attempt,
                        'fallback_attempts' => $fallbackAttempts,
                        'duration_ms' => $latencyMs,
                        'metadata' => ['code' => $exception->errorCode()],
                    ]);

                    $this->usage->recordFailure(
                        feature: $feature,
                        tenantId: $tenantId,
                        userId: $userId,
                        reason: $exception->errorCode(),
                        metadata: $exception->context(),
                        provider: $adapter->provider(),
                        task: $task,
                        correlationId: $correlationId,
                        errorCategory: $category,
                        fallbackAttempts: $fallbackAttempts,
                    );

                    $lastFailure = $exception;

                    if ($category === AiErrorCategory::ContentPolicy) {
                        // A safety refusal is final for the whole request: no
                        // retry on this provider, no second provider. Routing
                        // the same content elsewhere would bypass the refusal.
                        throw $exception;
                    }

                    if ($category === AiErrorCategory::Authentication) {
                        // A refused credential is an operator problem; the
                        // platform owner is told, then the chain moves on.
                        $this->notifyPlatformOwnerOfCredentialFailure($row, $adapter->provider());

                        break;
                    }

                    if (! $category->mayRetry()) {
                        // Validation and invalid-output failures are the
                        // request being wrong; another attempt of either kind
                        // would repeat the same mistake.
                        break;
                    }

                    if ($category === AiErrorCategory::RateLimit && $attempt <= $maxRetries) {
                        $retryAfter = $exception->context()['retry_after_seconds'] ?? null;

                        if (is_int($retryAfter) && $retryAfter >= self::LONG_RETRY_AFTER_SECONDS) {
                            // The provider asked for space: honour it by
                            // leaving rather than sleeping out the cooldown.
                            break;
                        }
                    }

                    if ($attempt <= $maxRetries) {
                        usleep($this->backoffMicroseconds($attempt));
                    }
                }
            }

            // This provider could not serve the request within its budget;
            // the chain moves to the next one.
            $fallbackAttempts++;
        }

        throw $lastFailure ?? AiUnavailable::notConfigured();
    }

    /**
     * Whether the database catalog has been seeded with providers.
     *
     * An empty catalog means the installation still routes through its
     * configuration — the state every deployment was in before the AI
     * administration existed.
     */
    private function catalogued(): bool
    {
        try {
            return AiProvider::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The database-driven chain for a task: task routes first, then the
     * default enabled providers, with credentials resolved per caller.
     *
     * @param  list<AiCapability>  $required
     * @return list<array{adapter: AiProviderContract, row: ?AiProvider, model: ?string}>
     */
    private function catalogHops(AiTask $task, ?int $tenantId, array $required): array
    {
        $tenant = $tenantId !== null ? Tenant::find($tenantId) : null;
        $byok = $this->settings->byokEnabled();
        $optIn = $tenant !== null && $this->settings->tenantByokOptIn($tenant);

        $hops = [];

        foreach ($this->router->chain($task, $tenant, $byok, $optIn, $required) as $hop) {
            $row = $hop['provider'];
            $adapter = $this->adapter($row, $tenant, $byok, $optIn && $hop['source'] === 'tenant');

            if ($adapter === null || ! $this->satisfies($adapter, $required)) {
                // No usable credential for this caller, or the adapter cannot
                // serve the request: the chain moves on without a failure row.
                continue;
            }

            $hops[] = ['adapter' => $adapter, 'row' => $row, 'model' => $hop['model']];
        }

        return $hops;
    }

    /**
     * The legacy configuration chain: primary provider, then configured
     * fallbacks, exactly as {@see ProviderRegistry} ordered them before the
     * routing table existed.
     *
     * @param  list<AiCapability>  $required
     * @return list<array{adapter: AiProviderContract, row: null, model: null}>
     */
    private function configuredHops(array $required): array
    {
        $hops = [];

        foreach ($this->registry->configured() as $provider) {
            if (! $this->satisfies($provider, $required)) {
                continue;
            }

            $hops[] = ['adapter' => $provider, 'row' => null, 'model' => null];
        }

        return $hops;
    }

    /**
     * Build the live adapter for one provider row, or null when the provider
     * has no usable credential for this caller.
     *
     * @return array{api_key: ?string, base_url: string, model: ?string, source: string}|null
     */
    private function adapter(AiProvider $row, ?Tenant $tenant, bool $byok, bool $tenantCredential): ?AiProviderContract
    {
        $credential = $this->credentials->resolve($row, $tenant, $byok, $tenantCredential);

        if ($credential === null) {
            return null;
        }

        $transport = (array) config('ai.transport', []);

        if (! $this->factory instanceof ConfiguredAiProviderFactory) {
            // A bound test factory builds adapters from config blocks; give
            // it the resolved material in the shape it understands.
            return $this->factory->make($row->driver, [
                'key' => $row->key,
                'base_url' => $credential['base_url'],
                'api_key' => $credential['api_key'],
                'model' => $credential['model'],
                'transport' => $transport,
            ]);
        }

        return $this->factory->makeResolved($row->driver, $row->key, $credential, $transport);
    }

    /**
     * @param  list<AiCapability>  $required
     */
    private function satisfies(AiProviderContract $adapter, array $required): bool
    {
        foreach ($required as $capability) {
            $satisfied = false;

            foreach ($adapter->capabilities() as $supported) {
                if ($supported->satisfies($capability)) {
                    $satisfied = true;

                    break;
                }
            }

            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Same-provider retries for one hop: the caller's ask, capped by policy.
     *
     * @param  array<string, mixed>  $options
     */
    private function maxRetries(array $options): int
    {
        $asked = $options['max_retries'] ?? config('ai.transport.retry_times', 1);

        return max(0, min(self::MAX_HOP_RETRIES, (int) $asked));
    }

    /**
     * Capped exponential backoff for the given attempt (1-based), in
     * microseconds: 50ms, 100ms, 200ms… capped at four seconds.
     */
    private function backoffMicroseconds(int $attempt): int
    {
        $milliseconds = min(self::MAX_BACKOFF_MS, 50 * (2 ** ($attempt - 1)));

        return (int) ($milliseconds * 1000);
    }

    private function correlationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Tell the platform owner a credential was refused, without ever
     * including the credential itself.
     */
    private function notifyPlatformOwnerOfCredentialFailure(?AiProvider $row, string $providerName): void
    {
        try {
            $this->settings->notifyProviderUnhealthy(
                providerName: $row?->name ?? $providerName,
                reason: 'authentication',
                providerId: $row?->getKey(),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
