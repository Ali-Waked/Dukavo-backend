<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTrigger;
use App\Http\Requests\ApiRequest;
use App\Models\AutomationRule;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;
use App\Services\Automation\AutomationDefinition;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class AutomationRuleRequest extends ApiRequest
{
    abstract protected function updating(): bool;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->updating() ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(AutomationRuleStatus::class)],
            'trigger' => [$required, Rule::enum(AutomationTrigger::class)],
            'store_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists(Store::class)],
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0', 'max:2592000'],
            'conditions' => ['sometimes', 'nullable', 'array', 'max:20'],
            'conditions.*.field' => ['required', 'string'],
            'conditions.*.operator' => ['required', Rule::in(AutomationDefinition::operatorKeys())],
            'conditions.*.value' => ['nullable'],
            'actions' => [$required, 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationDefinition::actionKeys())],
            'actions.*.config' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rule = $this->route('automationRule');
            $rule = $rule instanceof AutomationRule ? $rule : null;

            $triggerValue = $this->input('trigger', $rule?->trigger->value);
            $trigger = is_string($triggerValue) ? AutomationTrigger::tryFrom($triggerValue) : null;

            if (! $trigger instanceof AutomationTrigger) {
                return;
            }

            $this->validateConditions($validator, $trigger, $rule);
            $this->validateActions($validator);
        });
    }

    private function validateConditions(Validator $validator, AutomationTrigger $trigger, ?AutomationRule $rule): void
    {
        $conditions = $this->input('conditions', $rule?->conditions ?? []);

        if ($conditions === null) {
            return;
        }

        if (! is_array($conditions)) {
            return;
        }

        $fields = AutomationDefinition::fieldKeysFor($trigger);

        foreach (array_values($conditions) as $index => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $field = $condition['field'] ?? null;

            if (! is_string($field) || ! in_array($field, $fields, true)) {
                $validator->errors()->add(
                    "conditions.{$index}.field",
                    'That field is not available for the selected trigger.',
                );
            }

            $operatorValue = $condition['operator'] ?? null;
            $operator = is_string($operatorValue) ? AutomationConditionOperator::tryFrom($operatorValue) : null;

            if (! $operator instanceof AutomationConditionOperator) {
                continue;
            }

            $value = $condition['value'] ?? null;

            if (! $operator->requiresValue()) {
                continue;
            }

            if ($operator->acceptsMultipleValues()) {
                if (! is_array($value) || $value === []) {
                    $validator->errors()->add(
                        "conditions.{$index}.value",
                        'Choose one or more values for this condition.',
                    );

                    continue;
                }

                foreach ($value as $option) {
                    if (! is_scalar($option) || trim((string) $option) === '') {
                        $validator->errors()->add(
                            "conditions.{$index}.value",
                            'Each selected value must be a non-empty value.',
                        );

                        break;
                    }
                }

                continue;
            }

            if (! is_scalar($value) || trim((string) $value) === '') {
                $validator->errors()->add(
                    "conditions.{$index}.value",
                    'This condition needs a value.',
                );
            }
        }
    }

    private function validateActions(Validator $validator): void
    {
        $actions = $this->input('actions');

        if (! is_array($actions)) {
            return;
        }

        foreach (array_values($actions) as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''));
            $config = $action['config'] ?? null;

            if (! $type instanceof AutomationActionType || ! is_array($config)) {
                continue;
            }

            match ($type) {
                AutomationActionType::NotifyTeam => $this->validateNotifyConfig($validator, $index, $config),
                AutomationActionType::CreateTask => $this->validateTaskConfig($validator, $index, $config),
                AutomationActionType::ProposeAiAction => $this->validateAiConfig($validator, $index, $config),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateNotifyConfig(Validator $validator, int $index, array $config): void
    {
        $message = $config['message'] ?? null;

        if (! is_string($message) || trim($message) === '' || mb_strlen(trim($message)) > 1000) {
            $validator->errors()->add(
                "actions.{$index}.config.message",
                'The team notification needs a message of up to 1000 characters.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateTaskConfig(Validator $validator, int $index, array $config): void
    {
        $title = $config['title'] ?? null;

        if (! is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 180) {
            $validator->errors()->add(
                "actions.{$index}.config.title",
                'The task needs a title of up to 180 characters.',
            );
        }

        if (isset($config['description']) && (! is_string($config['description']) || mb_strlen($config['description']) > 5000)) {
            $validator->errors()->add(
                "actions.{$index}.config.description",
                'The task details may not exceed 5000 characters.',
            );
        }

        if (isset($config['priority']) && ! in_array((string) $config['priority'], AutomationDefinition::taskPriorities(), true)) {
            $validator->errors()->add(
                "actions.{$index}.config.priority",
                'Choose a supported task priority.',
            );
        }

        $assignedTo = $config['assigned_to'] ?? null;

        if ($assignedTo !== null && $assignedTo !== '') {
            $member = is_numeric($assignedTo) && User::query()
                ->whereKey((int) $assignedTo)
                ->whereHas('tenants', fn ($query) => $query->whereKey($this->tenantId()))
                ->exists();

            if (! $member) {
                $validator->errors()->add(
                    "actions.{$index}.config.assigned_to",
                    'The task assignee must be a user in this workspace.',
                );
            }
        }

        $dueInDays = $config['due_in_days'] ?? null;

        if ($dueInDays !== null && $dueInDays !== '' && (! is_numeric($dueInDays) || (int) $dueInDays < 1 || (int) $dueInDays > 365)) {
            $validator->errors()->add(
                "actions.{$index}.config.due_in_days",
                'The task due date must be between 1 and 365 days.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateAiConfig(Validator $validator, int $index, array $config): void
    {
        $actionType = $config['action_type'] ?? null;
        $registry = app(AiActionExecutorRegistry::class);

        if (! is_string($actionType) || ! $registry->has($actionType)) {
            $validator->errors()->add(
                "actions.{$index}.config.action_type",
                'Choose a supported AI action type.',
            );
        }

        if (! isset($config['payload']) || ! is_array($config['payload'])) {
            $validator->errors()->add(
                "actions.{$index}.config.payload",
                'The AI action needs a JSON payload object.',
            );
        }

        if (isset($config['summary']) && (! is_string($config['summary']) || mb_strlen($config['summary']) > 255)) {
            $validator->errors()->add(
                "actions.{$index}.config.summary",
                'The proposal summary may not exceed 255 characters.',
            );
        }
    }
}
