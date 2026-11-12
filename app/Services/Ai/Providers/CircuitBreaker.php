<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiErrorCategory;
use App\Enums\AiHealthState;
use App\Models\AiProvider;
use App\Models\AiProviderHealth;

/**
 * The circuit breaker that stops a failing provider from consuming requests.
 *
 * The live state is kept in memory for the decision (fast, no I/O on the
 * request path) and mirrored onto `ai_provider_health` for restarts and the
 * dashboard. The in-memory half is per-process; queue workers and web
 * requests therefore break independently, which is acceptable — the durable
 * row is what keeps the dashboard honest and seeds a fresh process.
 *
 * Rules:
 *  - `threshold` consecutive failures open the circuit for `cooldownSeconds`.
 *  - After the cooldown, one trial call is allowed (half-open). Success
 *    closes the circuit; another failure re-opens it for longer.
 *  - Authentication failures open the circuit immediately and for longer:
 *    a refused credential is an operator problem, not a transient fault.
 */
final class CircuitBreaker
{
    /** @var array<int, array{failures: int, opened_at: ?int, cooldown_until: ?int}> */
    private array $state = [];

    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 120,
        private readonly int $authCooldownSeconds = 3600,
    ) {}

    /**
     * Whether the provider may take a call right now.
     *
     * A circuit past its cooldown reads as allowed so the next call becomes
     * the half-open trial; the durable row decides for a process with no
     * in-memory state yet.
     */
    public function allows(AiProvider $provider): bool
    {
        $entry = $this->state[$provider->getKey()] ?? null;

        if ($entry === null || $entry['cooldown_until'] === null) {
            return $this->durableAllows($provider);
        }

        return $this->now() >= $entry['cooldown_until'];
    }

    /**
     * Record a success: the circuit closes.
     */
    public function recordSuccess(AiProvider $provider, int $latencyMs = 0): void
    {
        unset($this->state[$provider->getKey()]);

        $this->persist($provider, function (AiProviderHealth $health) use ($latencyMs): void {
            $health->fill([
                'state' => AiHealthState::Healthy,
                'consecutive_failures' => 0,
                'opened_at' => null,
                'cooldown_until' => null,
                'last_success_at' => now(),
                'last_latency_ms' => $latencyMs,
            ]);
        });
    }

    /**
     * Record a failure: count it, maybe open the circuit.
     */
    public function recordFailure(AiProvider $provider, AiErrorCategory $category, ?string $errorCode = null): void
    {
        $id = $provider->getKey();
        $entry = $this->state[$id] ?? ['failures' => 0, 'opened_at' => null, 'cooldown_until' => null];
        $entry['failures']++;

        $authentication = $category === AiErrorCategory::Authentication;
        $halfOpenRetry = $entry['cooldown_until'] !== null;
        $opened = $authentication || $entry['failures'] >= $this->threshold;

        if ($opened) {
            // A half-open probe that failed again backs off twice as hard,
            // capped at one hour; an authentication failure opens immediately
            // for the long cooldown because no retry can fix it.
            $cooldown = $authentication
                ? $this->authCooldownSeconds
                : min($this->cooldownSeconds * ($halfOpenRetry ? 2 : 1), $this->authCooldownSeconds);

            $entry['opened_at'] = $this->now();
            $entry['cooldown_until'] = $this->now() + $cooldown;
        }

        $this->state[$id] = $entry;

        $durable = $opened
            ? ['state' => AiHealthState::Open, 'opened_at' => now(), 'cooldown_until' => now()->addSeconds(max(0, $entry['cooldown_until'] - $this->now()))]
            : ['state' => AiHealthState::Degraded];

        $this->persist($provider, function (AiProviderHealth $health) use ($entry, $category, $errorCode, $durable): void {
            $health->fill($durable + [
                'consecutive_failures' => $entry['failures'],
                'failure_count' => (int) ($health->failure_count ?? 0) + 1,
                'last_error_code' => $errorCode ?? $category->value,
                'last_error_at' => now(),
            ]);
        });
    }

    /**
     * Record the outcome of an explicit connection test from the dashboard.
     *
     * A passing test closes an open circuit: the operator just proved the
     * provider works, which outranks the stale failure count.
     */
    public function recordCheck(AiProvider $provider, bool $ok, int $latencyMs = 0, string $status = 'ok'): void
    {
        if ($ok) {
            unset($this->state[$provider->getKey()]);
        }

        $this->persist($provider, function (AiProviderHealth $health) use ($ok, $latencyMs, $status): void {
            $health->fill([
                'last_checked_at' => now(),
                'last_check_status' => $status,
                'last_latency_ms' => $latencyMs,
            ]);

            if ($ok) {
                $health->fill([
                    'state' => AiHealthState::Healthy,
                    'consecutive_failures' => 0,
                    'opened_at' => null,
                    'cooldown_until' => null,
                    'last_success_at' => now(),
                ]);
            }
        });
    }

    /**
     * The durable row's opinion, for a process with no in-memory state.
     */
    private function durableAllows(AiProvider $provider): bool
    {
        $health = AiProviderHealth::query()
            ->where('provider_id', $provider->getKey())
            ->first();

        return $health === null || $health->allowsCalls();
    }

    /**
     * Persist the state without letting accounting break the request.
     *
     * @param  callable(AiProviderHealth): void  $mutate
     */
    private function persist(AiProvider $provider, callable $mutate): void
    {
        try {
            $health = AiProviderHealth::query()->firstOrNew(['provider_id' => $provider->getKey()]);
            $mutate($health);
            $health->save();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function now(): int
    {
        return time();
    }
}
