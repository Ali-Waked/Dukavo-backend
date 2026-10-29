<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationRunStatus;
use App\Events\Automation\AutomationEvent;
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
 */
final class AutomationEngine
{
    public function __construct(
        private readonly AutomationConditionEvaluator $conditions,
        private readonly AutomationActionRunner $actions,
    ) {}

    public function handle(AutomationEvent $event): void
    {
        $rules = AutomationRule::query()
            ->active()
            ->forTrigger($event->trigger())
            ->forStore($event->storeId())
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $this->runRule($rule, $event);
        }
    }

    private function runRule(AutomationRule $rule, AutomationEvent $event): void
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
                'matched' => false,
                'context' => $event->context(),
                'actions' => [],
                'started_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery claimed this event first.
            return;
        }

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

            $results = $this->actions->run($rule, $run, $event->context());
            $failed = array_values(array_filter($results, static fn (array $result): bool => $result['status'] !== 'completed'));
            $completed = count($results) - count($failed);

            $status = match (true) {
                $failed === [] => AutomationRunStatus::Completed,
                $completed === 0 => AutomationRunStatus::Failed,
                default => AutomationRunStatus::Partial,
            };

            $run->forceFill([
                'status' => $status,
                'actions' => $results,
                'error' => $failed === []
                    ? null
                    : Str::limit(implode('; ', array_map(
                        static fn (array $result): string => (string) ($result['data']['error'] ?? 'Action failed.'),
                        $failed,
                    )), 5000, ''),
                'finished_at' => now(),
            ])->save();

            $rule->forceFill([
                'run_count' => $rule->run_count + 1,
                'last_triggered_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $run->forceFill([
                'status' => AutomationRunStatus::Failed,
                'error' => Str::limit($exception->getMessage(), 5000, ''),
                'finished_at' => now(),
            ])->save();
        }
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
            'finished_at' => now(),
        ])->save();
    }
}
