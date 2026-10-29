<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationTask;
use App\Models\User;
use App\Services\Ai\Governance\AiActionService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\NotificationIntent;
use InvalidArgumentException;
use Throwable;

/**
 * Executes the safe actions attached to a rule.
 *
 * Failures are isolated per action: a broken AI proposal must not suppress a
 * task or team alert that was also configured. The run records each outcome so
 * a merchant can see exactly which action failed.
 */
final class AutomationActionRunner
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly AiActionService $aiActions,
        private readonly AutomationTemplateResolver $templates,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     * @return list<array{index: int, type: string, status: string, summary: string, data?: array<string, mixed>}>
     */
    public function run(AutomationRule $rule, AutomationRun $run, array $context): array
    {
        $results = [];

        foreach (array_values($rule->actions ?? []) as $index => $action) {
            $type = is_array($action) ? (string) ($action['type'] ?? '') : '';
            $config = is_array($action['config'] ?? null) ? $action['config'] : [];

            try {
                $data = match ($type) {
                    AutomationActionType::NotifyTeam->value => $this->notifyTeam($rule, $run, $index, $config, $context),
                    AutomationActionType::CreateTask->value => $this->createTask($rule, $run, $config, $context),
                    AutomationActionType::ProposeAiAction->value => $this->proposeAiAction($rule, $run, $index, $config, $context),
                    default => throw new InvalidArgumentException('Unsupported automation action.'),
                };

                $results[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'completed',
                    'summary' => $this->summaryFor($type, $data),
                    'data' => $data,
                ];
            } catch (Throwable $exception) {
                report($exception);

                $results[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'failed',
                    'summary' => 'This action could not be completed.',
                    'data' => [
                        'error' => $exception->getMessage(),
                    ],
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    private function notifyTeam(AutomationRule $rule, AutomationRun $run, int $index, array $config, array $context): array
    {
        $message = trim((string) $this->templates->resolve($config['message'] ?? '', $context));

        if ($message === '') {
            throw new InvalidArgumentException('The automation notification has no message.');
        }

        $queued = $this->notifications->dispatch(new NotificationIntent(
            eventKey: NotificationEventCatalog::TENANT_AUTOMATION_TRIGGERED,
            tenantId: (int) $rule->tenant_id,
            dedupeKey: sprintf('automation.triggered:rule:%d:run:%d:action:%d', $rule->getKey(), $run->getKey(), $index),
            data: [
                'rule' => $rule->name,
                'message' => $message,
                'trigger' => $rule->trigger->label(),
            ],
            actionUrl: '/automations',
        ));

        return ['queued_deliveries' => $queued];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    private function createTask(AutomationRule $rule, AutomationRun $run, array $config, array $context): array
    {
        $title = trim((string) $this->templates->resolve($config['title'] ?? '', $context));

        if ($title === '') {
            throw new InvalidArgumentException('The automation task has no title.');
        }

        $description = $this->templates->resolve($config['description'] ?? null, $context);
        $priority = (string) ($config['priority'] ?? 'normal');

        if (! in_array($priority, AutomationDefinition::taskPriorities(), true)) {
            $priority = 'normal';
        }

        $assignedTo = $this->resolveAssignee($config['assigned_to'] ?? null, (int) $rule->tenant_id);
        $dueInDays = $config['due_in_days'] ?? null;
        $dueAt = is_numeric($dueInDays) && (int) $dueInDays > 0
            ? now()->addDays(min(365, max(1, (int) $dueInDays)))
            : null;

        [$entityType, $entityId] = $this->subjectFor($rule->trigger, $context);

        $task = AutomationTask::query()->create([
            'tenant_id' => $rule->tenant_id,
            'automation_rule_id' => $rule->getKey(),
            'automation_run_id' => $run->getKey(),
            'store_id' => $context['store_id'] ?? $rule->store_id,
            'assigned_to' => $assignedTo,
            'created_by' => $rule->created_by,
            'title' => mb_substr($title, 0, 180),
            'description' => $description !== null ? mb_substr((string) $description, 0, 5000) : null,
            'priority' => $priority,
            'status' => 'open',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => [
                'trigger' => $rule->trigger->value,
                'event_key' => $run->event_key,
            ],
            'due_at' => $dueAt,
        ]);

        return ['task_id' => (int) $task->getKey()];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    private function proposeAiAction(AutomationRule $rule, AutomationRun $run, int $index, array $config, array $context): array
    {
        $actor = $rule->creator;

        if (! $actor instanceof User) {
            throw new InvalidArgumentException('The rule needs a creator before it can propose an AI action.');
        }

        $actionType = trim((string) ($config['action_type'] ?? ''));

        if ($actionType === '') {
            throw new InvalidArgumentException('The AI action proposal has no action type.');
        }

        $payload = $this->templates->resolve($config['payload'] ?? [], $context);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The AI action payload must be an object.');
        }

        $summary = trim((string) $this->templates->resolve($config['summary'] ?? 'Automation: '.$rule->name, $context));

        $proposal = $this->aiActions->propose(
            useCase: 'automation',
            actionType: $actionType,
            payload: $payload,
            actor: $actor,
            summary: $summary !== '' ? $summary : null,
            idempotencyKey: sprintf('automation:rule:%d:run:%d:action:%d', $rule->getKey(), $run->getKey(), $index),
        );

        $proposal = $this->aiActions->submit($proposal, $actor);

        return [
            'ai_action_id' => (int) $proposal->getKey(),
            'ai_action_status' => $proposal->status->value,
        ];
    }

    private function resolveAssignee(mixed $value, int $tenantId): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('The task assignee must be a user in this workspace.');
        }

        $userId = (int) $value;

        $exists = User::query()
            ->whereKey($userId)
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenantId))
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('The task assignee must be a user in this workspace.');
        }

        return $userId;
    }

    /**
     * @param  array<string, scalar|null>  $context
     * @return array{0: ?string, 1: ?int}
     */
    private function subjectFor(AutomationTrigger $trigger, array $context): array
    {
        return match ($trigger) {
            AutomationTrigger::OrderCreated, AutomationTrigger::OrderStatusChanged => [
                'order',
                isset($context['order_id']) ? (int) $context['order_id'] : null,
            ],
            AutomationTrigger::InventoryLowStock => [
                'inventory',
                isset($context['inventory_id']) ? (int) $context['inventory_id'] : null,
            ],
            AutomationTrigger::ConversationMessageReceived => [
                'conversation',
                isset($context['conversation_id']) ? (int) $context['conversation_id'] : null,
            ],
            AutomationTrigger::RecoveryOpportunityDetected => [
                'recovery_opportunity',
                isset($context['opportunity_id']) ? (int) $context['opportunity_id'] : null,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summaryFor(string $type, array $data): string
    {
        return match ($type) {
            AutomationActionType::NotifyTeam->value => 'Team notification created.',
            AutomationActionType::CreateTask->value => 'Task #'.($data['task_id'] ?? '?').' created.',
            AutomationActionType::ProposeAiAction->value => 'AI action #'.($data['ai_action_id'] ?? '?').' sent for approval.',
            default => 'Action completed.',
        };
    }
}
