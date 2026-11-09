<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationInstallationStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTemplateStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AutomationInstallationEvent;
use App\Models\AutomationRule;
use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateInstallation;
use App\Models\AutomationTemplateVersion;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Installs platform templates into a merchant's workspace.
 *
 * The executable object is always a tenant-owned {@see AutomationRule}. The
 * installation row carries provenance only. A template update is never applied
 * silently: if the merchant changed the rule, the update is reported as
 * available and must be explicitly confirmed.
 */
final class AutomationTemplateInstaller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AutomationRuleService $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function install(
        AutomationTemplate $template,
        ?int $storeId,
        array $configuration,
        User $actor,
    ): AutomationTemplateInstallation {
        if (! $template->isInstallable()) {
            throw BusinessRuleViolation::because('This template is not published and cannot be installed.');
        }

        if ($storeId !== null && ! Store::query()->whereKey($storeId)->exists()) {
            throw BusinessRuleViolation::because('Choose a store in this workspace.');
        }

        $tenantId = $this->tenants->required()->getKey();
        $scopeKey = AutomationTemplateInstallation::scopeKey($storeId);

        $existing = AutomationTemplateInstallation::query()
            ->where('automation_template_id', $template->getKey())
            ->where('scope_key', $scopeKey)
            ->first();

        if ($existing instanceof AutomationTemplateInstallation && $existing->status !== AutomationInstallationStatus::Removed) {
            throw BusinessRuleViolation::because('This template is already installed for that scope.');
        }

        $configuration = $this->normaliseConfiguration($template, $configuration);
        $definition = $this->resolveDefinition($template, $configuration);
        $this->assertActionsAvailable($definition['actions']);

        return DB::transaction(function () use ($template, $storeId, $configuration, $definition, $actor, $tenantId, $scopeKey, $existing): AutomationTemplateInstallation {
            if ($existing instanceof AutomationTemplateInstallation) {
                $existing->delete();
            }

            $rule = $this->rules->create([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'trigger' => $definition['trigger'],
                'store_id' => $storeId,
                'conditions' => $definition['conditions'],
                'actions' => $definition['actions'],
                'automation_template_id' => $template->getKey(),
                'template_version' => $template->current_version,
                'configuration' => $configuration,
                'template_customized' => false,
                'installed_at' => now(),
            ], $actor);

            $installation = AutomationTemplateInstallation::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'automation_template_id' => $template->getKey(),
                'automation_rule_id' => $rule->getKey(),
                'scope_key' => $scopeKey,
                'installed_version' => $template->current_version,
                'configuration' => $configuration,
                'status' => AutomationInstallationStatus::Installed,
                'template_customized' => false,
                'installed_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'installed_at' => now(),
            ]);

            $this->log($installation, 'installed', $actor, [
                'version' => $template->current_version,
                'store_id' => $storeId,
            ]);

            return $installation->load(['template', 'rule', 'store', 'installer']);
        });
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function updateConfiguration(
        AutomationTemplateInstallation $installation,
        array $configuration,
        User $actor,
    ): AutomationTemplateInstallation {
        $template = $installation->template;
        $rule = $installation->rule;

        if (! $template instanceof AutomationTemplate || ! $rule instanceof AutomationRule) {
            throw BusinessRuleViolation::because('This installation is no longer linked to a rule.');
        }

        if ($installation->status === AutomationInstallationStatus::Removed) {
            throw BusinessRuleViolation::because('A removed installation cannot be reconfigured.');
        }

        $configuration = $this->normaliseConfiguration($template, $configuration);

        return DB::transaction(function () use ($installation, $template, $rule, $configuration, $actor): AutomationTemplateInstallation {
            $customised = (bool) ($rule->template_customized || $installation->template_customized);

            if (! $customised) {
                $definition = $this->resolveDefinition($template, $configuration);
                $this->assertActionsAvailable($definition['actions']);

                $this->rules->update($rule, [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'trigger' => $definition['trigger'],
                    'conditions' => $definition['conditions'],
                    'actions' => $definition['actions'],
                    'configuration' => $configuration,
                    'template_version' => $installation->installed_version,
                ], $actor);

                $rule->forceFill([
                    'template_customized' => false,
                    'configuration' => $configuration,
                ])->save();
            }

            $installation->forceFill([
                'configuration' => $configuration,
                'template_customized' => $customised,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($installation, 'configured', $actor, [
                'customised' => $customised,
            ]);

            return $installation->refresh()->load(['template', 'rule', 'store', 'installer']);
        });
    }

    public function applyUpdate(AutomationTemplateInstallation $installation, User $actor, bool $force = false): AutomationTemplateInstallation
    {
        $template = $installation->template;
        $rule = $installation->rule;

        if (! $template instanceof AutomationTemplate || ! $rule instanceof AutomationRule) {
            throw BusinessRuleViolation::because('This installation is no longer linked to a rule.');
        }

        if ($template->status === AutomationTemplateStatus::Archived) {
            throw BusinessRuleViolation::because('This template has been archived and cannot be updated.');
        }

        if (! $installation->hasUpdate($template)) {
            throw BusinessRuleViolation::because('This installation is already on the latest template version.');
        }

        if (($installation->template_customized || $rule->template_customized) && ! $force) {
            throw BusinessRuleViolation::because('This automation has local changes. Confirm replacing them before applying the template update.');
        }

        $configuration = $this->normaliseConfiguration($template, $installation->configuration ?? []);
        $definition = $this->versionDefinition($template, $template->current_version);
        $definition['conditions'] = $this->resolveConfigurationPlaceholders($definition['conditions'], $configuration);
        $definition['actions'] = $this->resolveConfigurationPlaceholders($definition['actions'], $configuration);
        $this->assertActionsAvailable($definition['actions']);

        $previousVersion = $installation->installed_version;

        return DB::transaction(function () use ($installation, $rule, $template, $definition, $configuration, $actor, $previousVersion): AutomationTemplateInstallation {
            $this->rules->update($rule, [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'trigger' => $definition['trigger'],
                'conditions' => $definition['conditions'],
                'actions' => $definition['actions'],
                'configuration' => $configuration,
                'template_version' => $template->current_version,
            ], $actor);

            $rule->forceFill([
                'template_customized' => false,
                'template_version' => $template->current_version,
                'configuration' => $configuration,
                'installed_at' => now(),
            ])->save();

            $installation->forceFill([
                'installed_version' => $template->current_version,
                'configuration' => $configuration,
                'template_customized' => false,
                'updated_by' => $actor->getKey(),
                'last_synced_at' => now(),
            ])->save();

            $this->log($installation, 'updated', $actor, [
                'from_version' => $previousVersion,
                'to_version' => $template->current_version,
            ]);

            return $installation->refresh()->load(['template', 'rule', 'store', 'installer']);
        });
    }

    public function setEnabled(AutomationTemplateInstallation $installation, bool $enabled, User $actor): AutomationTemplateInstallation
    {
        if ($installation->status === AutomationInstallationStatus::Removed) {
            throw BusinessRuleViolation::because('A removed installation cannot be enabled.');
        }

        $rule = $installation->rule;

        if (! $rule instanceof AutomationRule) {
            throw BusinessRuleViolation::because('This installation is no longer linked to a rule.');
        }

        if ($enabled) {
            $this->assertActionsAvailable($rule->actions ?? []);
        }

        return DB::transaction(function () use ($installation, $rule, $enabled, $actor): AutomationTemplateInstallation {
            $rule->forceFill([
                'status' => $enabled ? AutomationRuleStatus::Active : AutomationRuleStatus::Paused,
                'enabled_at' => $enabled ? ($rule->enabled_at ?? now()) : $rule->enabled_at,
                'updated_by' => $actor->getKey(),
            ])->save();

            $installation->forceFill([
                'status' => $enabled ? AutomationInstallationStatus::Installed : AutomationInstallationStatus::Disabled,
                'enabled_at' => $enabled ? ($installation->enabled_at ?? now()) : $installation->enabled_at,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($installation, $enabled ? 'enabled' : 'disabled', $actor);

            return $installation->refresh()->load(['template', 'rule', 'store', 'installer']);
        });
    }

    public function remove(AutomationTemplateInstallation $installation, User $actor): AutomationTemplateInstallation
    {
        if ($installation->status === AutomationInstallationStatus::Removed) {
            return $installation;
        }

        return DB::transaction(function () use ($installation, $actor): AutomationTemplateInstallation {
            $installation->rule?->delete();

            $installation->forceFill([
                'status' => AutomationInstallationStatus::Removed,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($installation, 'removed', $actor);

            return $installation->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePreview(AutomationTemplateInstallation $installation): array
    {
        $template = $installation->template;
        $rule = $installation->rule;

        if (! $template instanceof AutomationTemplate) {
            return ['available' => false];
        }

        $version = AutomationTemplateVersion::query()
            ->where('automation_template_id', $template->getKey())
            ->where('version', $template->current_version)
            ->first();

        $definition = $this->versionDefinition($template, $template->current_version);
        $configuration = $installation->configuration ?? [];
        $definition['conditions'] = $this->resolveConfigurationPlaceholders($definition['conditions'], $configuration);
        $definition['actions'] = $this->resolveConfigurationPlaceholders($definition['actions'], $configuration);

        return [
            'available' => $installation->hasUpdate($template),
            'installed_version' => $installation->installed_version,
            'current_version' => $template->current_version,
            'customized' => (bool) (($rule?->template_customized ?? false) || $installation->template_customized),
            'changelog' => $version?->changelog,
            'definition' => $definition,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, scalar|null>
     */
    private function normaliseConfiguration(AutomationTemplate $template, array $input): array
    {
        $schema = $template->configuration_schema ?? [];
        $known = array_column($schema, 'key');

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $known, true)) {
                throw BusinessRuleViolation::because(sprintf('Unknown template configuration option "%s".', $key));
            }
        }

        $configuration = [];

        foreach ($schema as $field) {
            if (! is_array($field) || ! isset($field['key'])) {
                continue;
            }

            $key = (string) $field['key'];
            $type = (string) ($field['type'] ?? 'text');
            $required = (bool) ($field['required'] ?? false);
            $value = array_key_exists($key, $input) ? $input[$key] : ($field['default'] ?? null);

            if ($value === null || $value === '') {
                if ($required) {
                    throw BusinessRuleViolation::because(sprintf('The "%s" configuration value is required.', (string) ($field['label'] ?? $key)));
                }

                $configuration[$key] = $type === 'boolean' ? false : null;

                continue;
            }

            $configuration[$key] = match ($type) {
                'number' => $this->numberValue($field, $value),
                'integer' => (int) $this->numberValue($field, $value),
                'boolean' => (bool) filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                'select' => $this->selectValue($field, $value),
                default => mb_substr(trim((string) $value), 0, 2000),
            };
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function numberValue(array $field, mixed $value): float
    {
        if (! is_numeric($value)) {
            throw BusinessRuleViolation::because(sprintf('The "%s" configuration value must be a number.', (string) ($field['label'] ?? $field['key'])));
        }

        $number = (float) $value;
        $min = isset($field['min']) && is_numeric($field['min']) ? (float) $field['min'] : null;
        $max = isset($field['max']) && is_numeric($field['max']) ? (float) $field['max'] : null;

        if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            throw BusinessRuleViolation::because(sprintf('The "%s" configuration value is outside its allowed range.', (string) ($field['label'] ?? $field['key'])));
        }

        return $number;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function selectValue(array $field, mixed $value): string
    {
        $options = array_map(
            static fn (mixed $option): string => is_array($option) ? (string) ($option['value'] ?? '') : (string) $option,
            $field['options'] ?? [],
        );

        $selected = (string) $value;

        if (! in_array($selected, $options, true)) {
            throw BusinessRuleViolation::because(sprintf('Choose a valid value for "%s".', (string) ($field['label'] ?? $field['key'])));
        }

        return $selected;
    }

    /**
     * @param  array<string, scalar|null>  $configuration
     * @return array{name: string, description: string, trigger: string, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    private function resolveDefinition(AutomationTemplate $template, array $configuration): array
    {
        $definition = $template->definition();

        return [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'trigger' => $definition['trigger'],
            'conditions' => $this->resolveConfigurationPlaceholders($definition['conditions'], $configuration),
            'actions' => $this->resolveConfigurationPlaceholders($definition['actions'], $configuration),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $configuration
     */
    private function resolveConfigurationPlaceholders(mixed $value, array $configuration, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return null;
        }

        if (is_array($value)) {
            $resolved = [];

            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolveConfigurationPlaceholders($item, $configuration, $depth + 1);
            }

            return $resolved;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^\{\{\s*config\.([a-z0-9_]+)\s*\}\}$/i', $value, $match) === 1) {
            return $configuration[$match[1]] ?? '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*config\.([a-z0-9_]+)\s*\}\}/i',
            static function (array $match) use ($configuration): string {
                $replacement = $configuration[$match[1]] ?? '';

                return is_scalar($replacement) ? (string) $replacement : '';
            },
            $value,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function assertActionsAvailable(array $actions): void
    {
        foreach ($actions as $action) {
            $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''));

            if (! $type instanceof AutomationActionType) {
                throw BusinessRuleViolation::because('This template contains an unsupported automation action.');
            }

            $reason = AutomationActionRegistry::availabilityReason($type);

            if ($reason !== null) {
                throw BusinessRuleViolation::because(sprintf(
                    'This template needs the "%s" action, which is not available in this workspace (%s).',
                    $type->label(),
                    str_replace('_', ' ', $reason),
                ));
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function log(AutomationTemplateInstallation $installation, string $type, User $actor, array $metadata = []): void
    {
        AutomationInstallationEvent::query()->create([
            'tenant_id' => $installation->tenant_id,
            'automation_template_installation_id' => $installation->getKey(),
            'type' => $type,
            'actor_id' => $actor->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{name: string, description: string, trigger: string, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, configuration_schema: list<array<string, mixed>>}
     */
    private function versionDefinition(AutomationTemplate $template, string $version): array
    {
        $snapshot = AutomationTemplateVersion::query()
            ->where('automation_template_id', $template->getKey())
            ->where('version', $version)
            ->first();

        if (! $snapshot instanceof AutomationTemplateVersion) {
            return $template->definition();
        }

        return [
            'name' => $snapshot->name,
            'description' => $snapshot->description,
            'trigger' => (string) $snapshot->trigger,
            'conditions' => $snapshot->conditions ?? [],
            'actions' => $snapshot->actions ?? [],
            'configuration_schema' => $snapshot->configuration_schema ?? [],
        ];
    }
}
