<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationRunLogLevel;
use App\Enums\AutomationRunStatus;
use App\Events\Automation\AutomationEvent;
use App\Events\Automation\ScheduledAutomationEvent;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Evaluates active rules for a single business event.
 *
 * The engine records every attempt before doing side effects, so a queue retry
 * cannot repeat a task or an approval proposal. Cooldowns are checked before
 * conditions and actions, and an unmatched event is recorded as a skipped run
 * rather than disappearing silently.
 *
 * Each run is documented by structured log lines and a duration, and a failed
 * or partially failed run can be retried without repeating the actions that
 * already succeeded.
 */
final class AutomationEngine
{
    public function __construct(
        private readonly AutomationConditionEvaluator $conditions,
        private readonly AutomationActionRunner $actions,
        private readonly AutomationRunLogger $logger,
    ) {}

    public function handle(AutomationEvent $event, string $source = 'event'): void
    {
        $source = $event instanceof ScheduledAutomationEvent ? 'scheduled' : $source;

        $rules = AutomationRule::query()
            ->active()
            ->forTrigger($event->trigger())
            ->forStore($event->storeId())
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $this->runRule($rule, $event, $source);
        }
    }

    /**
     * Re-runs the failed actions of a terminal run.
     *
     * Results from actions that already completed or were skipped are reused,
     * so a retry never duplicates a task, notification or customer email.
     */
    public function retry(AutomationRun $run): AutomationRun
    {
        if (! in_array($run->status, [AutomationRunStatus::Failed, AutomationRunStatus::Partial], true)) {
            throw BusinessRuleViolation::because('Only a failed or partially completed run can be retried.');
        }

        $rule = $run->rule()->first();

        if (! $rule instanceof AutomationRule) {
            throw BusinessRuleViolation::because('The automation rule for this run no longer exists.');
        }

        $run->forceFill([
            'status' => AutomationRunStatus::Running,
            'source' => 'retry',
            'attempts' => $run->attempts + 1,
            'error' => null,
            'duration_ms' => null,
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        $this->logger->info($run, 'Retry started.', ['attempt' => $run->attempts, 'source' => 'retry']);

        try {
            $results = $this->actions->run($rule, $run, $run->context ?? [], $run->actions ?? []);
            $this->applyResults($run, $rule, $results, false);
        } catch (Throwable $exception) {
            report($exception);

            $this->fail($run, $exception);
        }

        return $run->refresh();
    }

    private function runRule(AutomationRule $rule, AutomationEvent $event, string $source): void
    {
        $eventKey = Str::limit($event->dedupeKey(), 190, '');

        $alreadyHandled = AutomationRun::query()
            ->where('automation_rule_id', $rule->getKey())
            ->where('event_key', $eventKey)
            ->exists();

        if ($alreadyHandled) {
            return;
        }

        try {
            $run = AutomationRun::query()->create([
                'tenant_id' => $rule->tenant_id,
                'automation_rule_id' => $rule->getKey(),
                'trigger' => $event->trigger(),
                'event_key' => $eventKey,
                'status' => AutomationRunStatus::Running,
                'attempts' => 1,
                'source' => $source,
                'matched' => false,
                'context' => $event->context(),
                'actions' => [],
                'started_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery claimed this event first.
            return;
        }

        $this->logger->info($run, 'Rule evaluation started.', [
            'trigger' => $event->trigger()->value,
            'source' => $source,
        ]);

        try {
            if ($this->onCooldown($rule)) {
                $this->skip($run, 'cooldown_active');

                return;
            }

            if (! $this->conditions->matches($rule->conditions ?? [], $event->context())) {
                $this->skip($run, 'conditions_not_met');

                return;
            }

            $run->forceFill(['matched' => true])->save();
            $this->logger->info($run, 'Conditions matched.');

            $results = $this->actions->run($rule, $run, $event->context());
            $this->applyResults($run, $rule, $results, true);
        } catch (Throwable $exception) {
            report($exception);

            $this->fail($run, $exception);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function applyResults(AutomationRun $run, AutomationRule $rule, array $results, bool $countRuleRun): void
    {
        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? null) === 'failed',
        ));
        $completed = array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? null) === 'completed',
        ));
        $skipped = array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? null) === 'skipped',
        ));

        $status = match (true) {
            $failed === [] && $completed !== [] => AutomationRunStatus::Completed,
            $failed === [] && $skipped !== [] => AutomationRunStatus::Skipped,
            $failed !== [] && $completed === [] && $skipped === [] => AutomationRunStatus::Failed,
            $failed !== [] => AutomationRunStatus::Partial,
            default => AutomationRunStatus::Failed,
        };

        $error = match (true) {
            $failed !== [] => $this->failureMessage($failed),
            $status === AutomationRunStatus::Skipped => $this->skipMessage($skipped),
            $status === AutomationRunStatus::Failed => 'No automation action produced a result.',
            default => null,
        };

        $run->forceFill([
            'status' => $status,
            'actions' => $results,
            'error' => $error,
            'duration_ms' => $this->duration($run),
            'finished_at' => now(),
        ])->save();

        $level = match ($status) {
            AutomationRunStatus::Completed => 'completed',
            AutomationRunStatus::Skipped => 'skipped',
            default => 'failed',
        };

        $this->logger->log(
            $run,
            $status === AutomationRunStatus::Completed
                ? AutomationRunLogLevel::Info
                : AutomationRunLogLevel::Warning,
            'Rule evaluation '.$level.'.',
            [
                'status' => $status->value,
                'completed' => count($completed),
                'skipped' => count($skipped),
                'failed' => count($failed),
                'duration_ms' => $run->duration_ms,
            ],
        );

        if ($countRuleRun) {
            $rule->forceFill([
                'run_count' => $rule->run_count + 1,
                'last_triggered_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function failureMessage(array $results): string
    {
        return Str::limit(implode('; ', array_map(
            static fn (array $result): string => (string) ($result['data']['error'] ?? $result['summary'] ?? 'Action failed.'),
            $results,
        )), 5000, '');
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function skipMessage(array $results): string
    {
        $reasons = array_map(
            static fn (array $result): string => (string) ($result['data']['reason'] ?? $result['summary'] ?? 'Action skipped.'),
            $results,
        );

        return Str::limit(implode('; ', array_unique($reasons)), 5000, '');
    }

    private function fail(AutomationRun $run, Throwable $exception): void
    {
        $run->forceFill([
            'status' => AutomationRunStatus::Failed,
            'error' => Str::limit($exception->getMessage(), 5000, ''),
            'duration_ms' => $this->duration($run),
            'finished_at' => now(),
        ])->save();

        $this->logger->error($run, 'Rule evaluation failed.', [
            'error' => $exception->getMessage(),
            'exception' => $exception::class,
            'duration_ms' => $run->duration_ms,
        ]);
    }

    private function onCooldown(AutomationRule $rule): bool
    {
        if ($rule->cooldown_seconds <= 0 || $rule->last_triggered_at === null) {
            return false;
        }

        return $rule->last_triggered_at->greaterThan(now()->subSeconds($rule->cooldown_seconds));
    }

    private function skip(AutomationRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => AutomationRunStatus::Skipped,
            'matched' => false,
            'error' => $reason,
            'duration_ms' => $this->duration($run),
            'finished_at' => now(),
        ])->save();

        $this->logger->warning($run, 'Rule evaluation skipped: '.$reason, [
            'reason' => $reason,
            'duration_ms' => $run->duration_ms,
        ]);
    }

    private function duration(AutomationRun $run): int
    {
        if ($run->started_at === null) {
            return 0;
        }

        return (int) max(0, round($run->started_at->diffInMilliseconds(now())));
    }
}
