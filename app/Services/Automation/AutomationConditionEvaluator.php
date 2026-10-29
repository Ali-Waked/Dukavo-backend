<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationConditionOperator;

/**
 * Evaluates the stored, structured conditions of a rule.
 *
 * Conditions are ANDed. An unknown field or operator fails closed, so a rule
 * that references a field removed from an event stops matching instead of
 * accidentally matching everything.
 */
final class AutomationConditionEvaluator
{
    /**
     * @param  array<int, array{field?: mixed, operator?: mixed, value?: mixed}>  $conditions
     * @param  array<string, scalar|null>  $context
     */
    public function matches(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;

            if (! is_string($field) || ! array_key_exists($field, $context)) {
                return false;
            }

            if (! is_string($operator) || AutomationConditionOperator::tryFrom($operator) === null) {
                return false;
            }

            if (! AutomationConditionOperator::from($operator)->evaluate(
                $context[$field],
                $condition['value'] ?? null,
            )) {
                return false;
            }
        }

        return true;
    }
}
