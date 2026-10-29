<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTrigger;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;

/**
 * Creates and edits automation rules after validating their structure.
 *
 * Validation here is the last line of defence: even a caller that bypasses the
 * HTTP request cannot save an unknown trigger, field, operator or action. The
 * stored shape is a closed vocabulary, never executable text.
 */
final class AutomationRuleService
{
    public function __construct(
        private readonly AiActionExecutorRegistry $executors,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): AutomationRule
    {
        $normalised = $this->normalise($attributes, null);

        $rule = AutomationRule::query()->create($normalised + [
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);

        return $rule->load(['store', 'creator']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AutomationRule $rule, array $attributes, User $actor): AutomationRule
    {
        $normalised = $this->normalise($attributes, $rule);

        $rule->fill($normalised + ['updated_by' => $actor->getKey()])->save();

        return $rule->load(['store', 'creator']);
    }

    public function delete(AutomationRule $rule): void
    {
        $rule->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes, ?AutomationRule $existing): array
    {
        $triggerValue = $attributes['trigger'] ?? $existing?->trigger->value;
        $trigger = is_string($triggerValue) ? AutomationTrigger::tryFrom($triggerValue) : null;

        if (! $trigger instanceof AutomationTrigger) {
            throw BusinessRuleViolation::because('Choose a supported automation trigger.');
        }

        $conditions = array_key_exists('conditions', $attributes)
            ? ($attributes['conditions'] ?? [])
            : ($existing?->conditions ?? []);
        $actions = array_key_exists('actions', $attributes)
            ? $attributes['actions']
            : ($existing?->actions ?? []);

        $normalised = [
            'trigger' => $trigger,
            'conditions' => $this->normaliseConditions($conditions, $trigger),
            'actions' => $this->normaliseActions($actions),
            'status' => $existing?->status ?? AutomationRuleStatus::Paused,
        ];

        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $value = $attributes[$field];
                $normalised[$field] = is_string($value) && trim($value) !== ''
                    ? trim($value)
                    : null;
            }
        }

        $name = array_key_exists('name', $normalised) ? $normalised['name'] : $existing?->name;

        if (! is_string($name) || trim($name) === '') {
            throw BusinessRuleViolation::because('Give the automation rule a name.');
        }

        if (array_key_exists('status', $attributes)) {
            $status = $attributes['status'] instanceof AutomationRuleStatus
                ? $attributes['status']
                : AutomationRuleStatus::tryFrom((string) $attributes['status']);

            if (! $status instanceof AutomationRuleStatus) {
                throw BusinessRuleViolation::because('Choose a valid automation status.');
            }

            $normalised['status'] = $status;
        }

        if (array_key_exists('store_id', $attributes)) {
            $normalised['store_id'] = $attributes['store_id'] !== null && $attributes['store_id'] !== ''
                ? (int) $attributes['store_id']
                : null;
        }

        if (array_key_exists('cooldown_seconds', $attributes)) {
            $normalised['cooldown_seconds'] = max(0, min(2592000, (int) $attributes['cooldown_seconds']));
        }

        return $normalised;
    }

    /**
     * @return list<array{field: string, operator: string, value?: mixed}>
     */
    private function normaliseConditions(mixed $conditions, AutomationTrigger $trigger): array
    {
        if ($conditions === null) {
            return [];
        }

        if (! is_array($conditions)) {
            throw BusinessRuleViolation::because('Automation conditions must be a list.');
        }

        if (count($conditions) > 20) {
            throw BusinessRuleViolation::because('An automation rule can have at most 20 conditions.');
        }

        $fields = AutomationDefinition::fieldKeysFor($trigger);
        $normalised = [];

        foreach (array_values($conditions) as $condition) {
            if (! is_array($condition)) {
                throw BusinessRuleViolation::because('Each automation condition must be an object.');
            }

            $field = $condition['field'] ?? null;
            $operatorValue = $condition['operator'] ?? null;
            $operator = is_string($operatorValue) ? AutomationConditionOperator::tryFrom($operatorValue) : null;

            if (! is_string($field) || ! in_array($field, $fields, true)) {
                throw BusinessRuleViolation::because('That field is not available for the selected trigger.');
            }

            if (! $operator instanceof AutomationConditionOperator) {
                throw BusinessRuleViolation::because('Choose a supported condition operator.');
            }

            $value = $condition['value'] ?? null;

            if (! $operator->requiresValue()) {
                $value = null;
            } else {
                $value = $this->normaliseConditionValue($value, $operator);
            }

            $normalised[] = array_filter([
                'field' => $field,
                'operator' => $operator->value,
                'value' => $value,
            ], static fn (mixed $item, string $key): bool => $key !== 'value' || $item !== null, ARRAY_FILTER_USE_BOTH);
        }

        return $normalised;
    }

    private function normaliseConditionValue(mixed $value, AutomationConditionOperator $operator): mixed
    {
        if ($operator->acceptsMultipleValues()) {
            if (! is_array($value) || count($value) > 50) {
                throw BusinessRuleViolation::because('Choose one or more values for that condition.');
            }

            $values = array_values(array_filter(array_map(
                static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
                $value,
            ), static fn (?string $item): bool => $item !== null));

            if ($values === []) {
                throw BusinessRuleViolation::because('Choose at least one value for that condition.');
            }

            return $values;
        }

        if (! is_scalar($value)) {
            throw BusinessRuleViolation::because('That condition needs a value.');
        }

        $string = trim((string) $value);

        if ($string === '') {
            throw BusinessRuleViolation::because('That condition needs a value.');
        }

        return $string;
    }

    /**
     * @return list<array{type: string, config: array<string, mixed>}>
     */
    private function normaliseActions(mixed $actions): array
    {
        if (! is_array($actions) || $actions === [] || count($actions) > 10) {
            throw BusinessRuleViolation::because('Add between 1 and 10 actions to the rule.');
        }

        $normalised = [];

        foreach (array_values($actions) as $action) {
            if (! is_array($action)) {
                throw BusinessRuleViolation::because('Each automation action must be an object.');
            }

            $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''));

            if (! $type instanceof AutomationActionType) {
                throw BusinessRuleViolation::because('Choose a supported automation action.');
            }

            $config = $action['config'] ?? [];

            if (! is_array($config)) {
                throw BusinessRuleViolation::because('The action configuration must be an object.');
            }

            $normalised[] = [
                'type' => $type->value,
                'config' => match ($type) {
                    AutomationActionType::NotifyTeam => $this->notifyConfig($config),
                    AutomationActionType::CreateTask => $this->taskConfig($config),
                    AutomationActionType::ProposeAiAction => $this->aiActionConfig($config),
                },
            ];
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{message: string}
     */
    private function notifyConfig(array $config): array
    {
        $message = trim((string) ($config['message'] ?? ''));

        if ($message === '' || mb_strlen($message) > 1000) {
            throw BusinessRuleViolation::because('The team notification needs a message of up to 1000 characters.');
        }

        return ['message' => $message];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function taskConfig(array $config): array
    {
        $title = trim((string) ($config['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 180) {
            throw BusinessRuleViolation::because('The task needs a title of up to 180 characters.');
        }

        $description = isset($config['description']) ? trim((string) $config['description']) : '';
        $priority = (string) ($config['priority'] ?? 'normal');

        if (! in_array($priority, AutomationDefinition::taskPriorities(), true)) {
            throw BusinessRuleViolation::because('Choose a supported task priority.');
        }

        $assignedTo = $config['assigned_to'] ?? null;

        if ($assignedTo !== null && $assignedTo !== '' && ! is_numeric($assignedTo)) {
            throw BusinessRuleViolation::because('The task assignee must be a user in this workspace.');
        }

        $dueInDays = $config['due_in_days'] ?? null;

        if ($dueInDays !== null && $dueInDays !== '' && (! is_numeric($dueInDays) || (int) $dueInDays < 1 || (int) $dueInDays > 365)) {
            throw BusinessRuleViolation::because('The task due date must be between 1 and 365 days.');
        }

        return array_filter([
            'title' => $title,
            'description' => $description !== '' ? mb_substr($description, 0, 5000) : null,
            'priority' => $priority,
            'assigned_to' => $assignedTo !== null && $assignedTo !== '' ? (int) $assignedTo : null,
            'due_in_days' => $dueInDays !== null && $dueInDays !== '' ? (int) $dueInDays : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function aiActionConfig(array $config): array
    {
        $actionType = trim((string) ($config['action_type'] ?? ''));

        if ($actionType === '' || ! $this->executors->has($actionType)) {
            throw BusinessRuleViolation::because('Choose a supported AI action type.');
        }

        $payload = $config['payload'] ?? null;

        if (! is_array($payload)) {
            throw BusinessRuleViolation::because('The AI action needs a JSON payload object.');
        }

        $summary = trim((string) ($config['summary'] ?? ''));

        return [
            'action_type' => $actionType,
            'summary' => $summary !== '' ? mb_substr($summary, 0, 255) : null,
            'payload' => $payload,
        ];
    }
}
