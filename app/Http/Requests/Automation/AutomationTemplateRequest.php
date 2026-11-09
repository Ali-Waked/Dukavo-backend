<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Http\Requests\ApiRequest;
use App\Services\Automation\AutomationDefinition;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class AutomationTemplateRequest extends ApiRequest
{
    abstract protected function updating(): bool;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->updating() ? 'sometimes' : 'required';

        return [
            'key' => ['sometimes', 'string', 'max:120', 'alpha_dash'],
            'name' => [$required, 'string', 'max:180'],
            'description' => [$required, 'string', 'max:5000'],
            'category' => [$required, Rule::in(AutomationDefinition::templateCategories())],
            'trigger' => [$required, Rule::enum(AutomationTrigger::class)],
            'conditions' => ['sometimes', 'nullable', 'array', 'max:20'],
            'conditions.*.field' => ['required', 'string'],
            'conditions.*.operator' => ['required', Rule::in(AutomationDefinition::operatorKeys())],
            'conditions.*.value' => ['nullable'],
            'actions' => [$required, 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationDefinition::actionKeys())],
            'actions.*.config' => ['required', 'array'],
            'configuration_schema' => ['sometimes', 'array', 'max:20'],
            'configuration_schema.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,40}$/'],
            'configuration_schema.*.label' => ['required', 'string', 'max:120'],
            'configuration_schema.*.type' => ['required', Rule::in(['text', 'textarea', 'number', 'integer', 'boolean', 'select'])],
            'configuration_schema.*.required' => ['boolean'],
            'configuration_schema.*.default' => ['nullable'],
            'configuration_schema.*.options' => ['sometimes', 'array', 'max:50'],
            'configuration_schema.*.help' => ['sometimes', 'nullable', 'string', 'max:255'],
            'configuration_schema.*.min' => ['sometimes', 'nullable', 'numeric'],
            'configuration_schema.*.max' => ['sometimes', 'nullable', 'numeric'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'version' => ['sometimes', 'nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'changelog' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $triggerValue = $this->input('trigger');
            $trigger = is_string($triggerValue) ? AutomationTrigger::tryFrom($triggerValue) : null;

            if (! $trigger instanceof AutomationTrigger) {
                return;
            }

            $fields = AutomationDefinition::fieldKeysFor($trigger);
            $conditions = $this->input('conditions', []);

            if (is_array($conditions)) {
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
                }
            }

            $actions = $this->input('actions', []);

            if (is_array($actions)) {
                foreach (array_values($actions) as $index => $action) {
                    if (is_array($action) && ($action['type'] ?? null) === AutomationActionType::SendCustomerWhatsApp->value) {
                        $validator->errors()->add(
                            "actions.{$index}.type",
                            'WhatsApp automations are not available yet.',
                        );
                    }
                }
            }
        });
    }
}
