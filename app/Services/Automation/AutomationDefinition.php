<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationTrigger;

/**
 * The catalogue of automation triggers, fields, operators and actions.
 *
 * The actual definitions live in {@see AutomationTriggerRegistry} and
 * {@see AutomationActionRegistry}; this class keeps the stable static API that
 * controllers, requests and older callers already use.
 */
final class AutomationDefinition
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function triggers(): array
    {
        return AutomationTriggerRegistry::all();
    }

    /**
     * @return list<array{value: string, label: string, multiple: bool, needs_value: bool}>
     */
    public static function operators(): array
    {
        return array_map(static fn (AutomationConditionOperator $operator): array => [
            'value' => $operator->value,
            'label' => $operator->label(),
            'multiple' => $operator->acceptsMultipleValues(),
            'needs_value' => $operator->requiresValue(),
        ], AutomationConditionOperator::cases());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function actions(): array
    {
        return AutomationActionRegistry::all();
    }

    /**
     * @return list<string>
     */
    public static function triggerKeys(): array
    {
        return AutomationTriggerRegistry::keys();
    }

    /**
     * @return list<string>
     */
    public static function actionKeys(): array
    {
        return AutomationActionRegistry::keys();
    }

    /**
     * @return list<string>
     */
    public static function availableActionKeys(): array
    {
        return AutomationActionRegistry::availableKeys();
    }

    /**
     * @return list<string>
     */
    public static function operatorKeys(): array
    {
        return array_map(static fn (AutomationConditionOperator $operator): string => $operator->value, AutomationConditionOperator::cases());
    }

    /**
     * @return list<string>
     */
    public static function fieldKeysFor(AutomationTrigger $trigger): array
    {
        return AutomationTriggerRegistry::fieldKeysFor($trigger);
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function sampleContextFor(AutomationTrigger $trigger): array
    {
        return AutomationTriggerRegistry::sampleContext($trigger);
    }

    /**
     * @return list<string>
     */
    public static function taskPriorities(): array
    {
        return ['low', 'normal', 'high', 'urgent'];
    }

    /**
     * @return list<string>
     */
    public static function templateCategories(): array
    {
        return ['orders', 'inventory', 'conversations', 'recovery', 'customers', 'scheduled', 'general'];
    }
}
