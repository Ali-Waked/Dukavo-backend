<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationTemplateStatus;
use App\Enums\AutomationTrigger;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-owned template authoring.
 *
 * A template is a blueprint, never an executable rule. Every published change
 * becomes an immutable version so a merchant installation can say exactly which
 * definition it came from and decide whether to accept the update.
 */
final class AutomationTemplateService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): AutomationTemplate
    {
        $normalised = $this->normalise($attributes);
        $normalised['key'] = $this->uniqueKey((string) ($attributes['key'] ?? $attributes['name'] ?? 'template'));
        $normalised['current_version'] = '1.0.0';
        $normalised['status'] = AutomationTemplateStatus::Draft;
        $normalised['created_by'] = $actor->getKey();
        $normalised['updated_by'] = $actor->getKey();

        return DB::transaction(function () use ($normalised, $attributes, $actor): AutomationTemplate {
            $template = AutomationTemplate::query()->create($normalised);
            $this->snapshot($template, $actor, (string) ($attributes['changelog'] ?? 'Initial version.'));

            return $template->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AutomationTemplate $template, array $attributes, User $actor): AutomationTemplate
    {
        // The request may be partial. Merge the current definition first so a
        // rename or reorder does not need the whole template in the payload.
        $normalised = $this->normalise([
            'name' => $attributes['name'] ?? $template->name,
            'description' => $attributes['description'] ?? $template->description,
            'category' => $attributes['category'] ?? $template->category,
            'trigger' => $attributes['trigger'] ?? $template->trigger->value,
            'conditions' => $attributes['conditions'] ?? $template->conditions ?? [],
            'actions' => $attributes['actions'] ?? $template->actions ?? [],
            'configuration_schema' => $attributes['configuration_schema'] ?? $template->configuration_schema ?? [],
            'icon' => array_key_exists('icon', $attributes) ? $attributes['icon'] : $template->icon,
            'sort_order' => $attributes['sort_order'] ?? $template->sort_order,
        ]);

        $definitionChanged = $this->definitionChanged($template, $normalised);
        $version = $definitionChanged
            ? ($this->requestedVersion($attributes, $template->current_version) ?? $this->nextVersion($template->current_version))
            : $template->current_version;

        $normalised['current_version'] = $version;
        $normalised['updated_by'] = $actor->getKey();

        return DB::transaction(function () use ($template, $normalised, $definitionChanged, $version, $actor, $attributes): AutomationTemplate {
            $template->fill($normalised)->save();

            if ($definitionChanged) {
                $this->snapshot($template, $actor, (string) ($attributes['changelog'] ?? 'Definition updated to version '.$version.'.'));
            }

            return $template->refresh();
        });
    }

    public function publish(AutomationTemplate $template, User $actor): AutomationTemplate
    {
        if ($template->status === AutomationTemplateStatus::Archived) {
            throw BusinessRuleViolation::because('An archived template cannot be published again.');
        }

        if ($template->actions === []) {
            throw BusinessRuleViolation::because('A template needs at least one action before it can be published.');
        }

        $template->forceFill([
            'status' => AutomationTemplateStatus::Active,
            'updated_by' => $actor->getKey(),
        ])->save();

        return $template->refresh();
    }

    public function archive(AutomationTemplate $template, User $actor): AutomationTemplate
    {
        $template->forceFill([
            'status' => AutomationTemplateStatus::Archived,
            'updated_by' => $actor->getKey(),
        ])->save();

        return $template->refresh();
    }

    public function delete(AutomationTemplate $template): void
    {
        if ($template->installations()->exists()) {
            throw BusinessRuleViolation::because('This template has merchant installations. Archive it instead of deleting it.');
        }

        $template->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes): array
    {
        $name = isset($attributes['name']) ? trim((string) $attributes['name']) : null;

        if ($name === null || $name === '' || mb_strlen($name) > 180) {
            throw BusinessRuleViolation::because('Give the template a name of up to 180 characters.');
        }

        $description = isset($attributes['description']) ? trim((string) $attributes['description']) : '';

        if ($description === '' || mb_strlen($description) > 5000) {
            throw BusinessRuleViolation::because('Give the template a description of up to 5000 characters.');
        }

        $category = (string) ($attributes['category'] ?? 'general');

        if (! in_array($category, AutomationDefinition::templateCategories(), true)) {
            throw BusinessRuleViolation::because('Choose a supported template category.');
        }

        $trigger = AutomationTrigger::tryFrom((string) ($attributes['trigger'] ?? ''));

        if (! $trigger instanceof AutomationTrigger) {
            throw BusinessRuleViolation::because('Choose a supported automation trigger.');
        }

        $conditions = $this->normaliseConditions($attributes['conditions'] ?? [], $trigger);
        $actions = $this->normaliseActions($attributes['actions'] ?? []);
        $schema = $this->normaliseSchema($attributes['configuration_schema'] ?? []);

        return [
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'trigger' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
            'configuration_schema' => $schema,
            'icon' => isset($attributes['icon']) ? mb_substr(trim((string) $attributes['icon']), 0, 60) : null,
            'sort_order' => max(0, min(9999, (int) ($attributes['sort_order'] ?? 0))),
        ];
    }

    /**
     * @return list<array{field: string, operator: string, value?: mixed}>
     */
    private function normaliseConditions(mixed $conditions, AutomationTrigger $trigger): array
    {
        if (! is_array($conditions) || count($conditions) > 20) {
            throw BusinessRuleViolation::because('A template can have at most 20 conditions.');
        }

        $fields = AutomationDefinition::fieldKeysFor($trigger);
        $normalised = [];

        foreach (array_values($conditions) as $condition) {
            if (! is_array($condition)) {
                throw BusinessRuleViolation::because('Each template condition must be an object.');
            }

            $field = $condition['field'] ?? null;
            $operator = AutomationConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

            if (! is_string($field) || ! in_array($field, $fields, true)) {
                throw BusinessRuleViolation::because('That field is not available for the selected trigger.');
            }

            if (! $operator instanceof AutomationConditionOperator) {
                throw BusinessRuleViolation::because('Choose a supported condition operator.');
            }

            $item = ['field' => $field, 'operator' => $operator->value];

            if ($operator->requiresValue()) {
                $value = $condition['value'] ?? null;

                if (! is_scalar($value) && ! is_array($value)) {
                    throw BusinessRuleViolation::because('That condition needs a value.');
                }

                $item['value'] = $value;
            }

            $normalised[] = $item;
        }

        return $normalised;
    }

    /**
     * @return list<array{type: string, config: array<string, mixed>}>
     */
    private function normaliseActions(mixed $actions): array
    {
        if (! is_array($actions) || $actions === [] || count($actions) > 10) {
            throw BusinessRuleViolation::because('Add between 1 and 10 actions to the template.');
        }

        $normalised = [];

        foreach (array_values($actions) as $action) {
            if (! is_array($action)) {
                throw BusinessRuleViolation::because('Each template action must be an object.');
            }

            $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''));

            if (! $type instanceof AutomationActionType) {
                throw BusinessRuleViolation::because('Choose a supported automation action.');
            }

            if ($type === AutomationActionType::SendCustomerWhatsApp) {
                throw BusinessRuleViolation::because('WhatsApp automations are not available, so a template cannot publish one.');
            }

            $config = $action['config'] ?? [];

            if (! is_array($config)) {
                throw BusinessRuleViolation::because('The action configuration must be an object.');
            }

            $normalised[] = ['type' => $type->value, 'config' => $config];
        }

        return $normalised;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normaliseSchema(mixed $schema): array
    {
        if (! is_array($schema) || count($schema) > 20) {
            throw BusinessRuleViolation::because('A template can declare at most 20 configuration fields.');
        }

        $normalised = [];
        $seen = [];

        foreach (array_values($schema) as $field) {
            if (! is_array($field)) {
                throw BusinessRuleViolation::because('Each configuration field must be an object.');
            }

            $key = (string) ($field['key'] ?? '');

            if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) || isset($seen[$key])) {
                throw BusinessRuleViolation::because('Configuration keys must be unique snake_case identifiers.');
            }

            $seen[$key] = true;

            $type = (string) ($field['type'] ?? 'text');

            if (! in_array($type, ['text', 'textarea', 'number', 'integer', 'boolean', 'select'], true)) {
                throw BusinessRuleViolation::because('Choose a supported configuration field type.');
            }

            $normalised[] = array_filter([
                'key' => $key,
                'label' => mb_substr(trim((string) ($field['label'] ?? Str::headline($key))), 0, 120),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'default' => $field['default'] ?? null,
                'options' => $type === 'select' ? $this->normaliseOptions($field['options'] ?? []) : null,
                'help' => isset($field['help']) ? mb_substr(trim((string) $field['help']), 0, 255) : null,
                'min' => isset($field['min']) && is_numeric($field['min']) ? (float) $field['min'] : null,
                'max' => isset($field['max']) && is_numeric($field['max']) ? (float) $field['max'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $normalised;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function normaliseOptions(mixed $options): array
    {
        if (! is_array($options) || $options === [] || count($options) > 50) {
            throw BusinessRuleViolation::because('A select field needs between 1 and 50 options.');
        }

        return array_map(static function (mixed $option): array {
            if (is_scalar($option)) {
                return ['value' => (string) $option, 'label' => (string) $option];
            }

            if (! is_array($option) || ! is_scalar($option['value'] ?? null)) {
                throw BusinessRuleViolation::because('Each select option needs a value.');
            }

            return [
                'value' => (string) $option['value'],
                'label' => (string) ($option['label'] ?? $option['value']),
            ];
        }, array_values($options));
    }

    /**
     * @param  array<string, mixed>  $normalised
     */
    private function definitionChanged(AutomationTemplate $template, array $normalised): bool
    {
        return $normalised['trigger'] !== $template->trigger
            || $normalised['conditions'] !== ($template->conditions ?? [])
            || $normalised['actions'] !== ($template->actions ?? [])
            || $normalised['configuration_schema'] !== ($template->configuration_schema ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requestedVersion(array $attributes, string $current): ?string
    {
        $requested = $attributes['version'] ?? null;

        if (! is_string($requested) || ! preg_match('/^\d+\.\d+\.\d+$/', $requested)) {
            return null;
        }

        return version_compare($requested, $current, '>') ? $requested : null;
    }

    private function nextVersion(string $current): string
    {
        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $matches)) {
            return '1.0.0';
        }

        return sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) $matches[3] + 1);
    }

    private function uniqueKey(string $value): string
    {
        $base = Str::slug($value) ?: 'automation-template';
        $key = $base;
        $suffix = 2;

        while (AutomationTemplate::query()->where('key', $key)->exists()) {
            $key = $base.'-'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function snapshot(AutomationTemplate $template, User $actor, string $changelog): AutomationTemplateVersion
    {
        $definition = $template->definition();

        return AutomationTemplateVersion::query()->updateOrCreate(
            [
                'automation_template_id' => $template->getKey(),
                'version' => $template->current_version,
            ],
            [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'category' => $definition['category'],
                'trigger' => $definition['trigger'],
                'conditions' => $definition['conditions'],
                'actions' => $definition['actions'],
                'configuration_schema' => $definition['configuration_schema'],
                'checksum' => hash('sha256', (string) json_encode($definition)),
                'changelog' => mb_substr(trim($changelog) !== '' ? trim($changelog) : 'Definition updated.', 0, 2000),
                'created_by' => $actor->getKey(),
            ],
        );
    }
}
