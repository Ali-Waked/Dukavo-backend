<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The comparison operators a rule condition may use.
 *
 * Comparisons are deliberately simple and type-aware enough for automation
 * fields. They never evaluate PHP, SQL or expressions supplied by a user.
 */
enum AutomationConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case In = 'in';
    case NotIn = 'not_in';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'Equals',
            self::NotEquals => 'Does not equal',
            self::Contains => 'Contains',
            self::In => 'Is one of',
            self::NotIn => 'Is not one of',
            self::GreaterThan => 'Is greater than',
            self::GreaterThanOrEqual => 'Is at least',
            self::LessThan => 'Is less than',
            self::LessThanOrEqual => 'Is at most',
            self::IsEmpty => 'Is empty',
            self::IsNotEmpty => 'Is not empty',
        };
    }

    public function requiresValue(): bool
    {
        return ! in_array($this, [self::IsEmpty, self::IsNotEmpty], true);
    }

    public function acceptsMultipleValues(): bool
    {
        return in_array($this, [self::In, self::NotIn], true);
    }

    public function evaluate(mixed $actual, mixed $expected): bool
    {
        return match ($this) {
            self::Equals => $this->normalise($actual) === $this->normalise($expected),
            self::NotEquals => $this->normalise($actual) !== $this->normalise($expected),
            self::Contains => is_array($actual)
                ? in_array($this->normalise($expected), array_map($this->normalise(...), $actual), true)
                : str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            self::In => $this->isOneOf($actual, $expected),
            self::NotIn => ! $this->isOneOf($actual, $expected),
            self::GreaterThan => $this->number($actual) > $this->number($expected),
            self::GreaterThanOrEqual => $this->number($actual) >= $this->number($expected),
            self::LessThan => $this->number($actual) < $this->number($expected),
            self::LessThanOrEqual => $this->number($actual) <= $this->number($expected),
            self::IsEmpty => $actual === null || $actual === '' || $actual === [],
            self::IsNotEmpty => ! ($actual === null || $actual === '' || $actual === []),
        };
    }

    private function isOneOf(mixed $actual, mixed $expected): bool
    {
        $options = is_array($expected) ? $expected : [$expected];

        return in_array($this->normalise($actual), array_map($this->normalise(...), $options), true);
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function normalise(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(',', array_map($this->normalise(...), $value));
        }

        return mb_strtolower(trim((string) $value));
    }
}
