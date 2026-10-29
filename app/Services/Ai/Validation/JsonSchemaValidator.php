<?php

declare(strict_types=1);

namespace App\Services\Ai\Validation;

/**
 * Validates a decoded answer against a JSON Schema.
 *
 * A provider that enforces a schema server-side is a convenience, not a
 * guarantee: the same model behind a different endpoint, a proxy that rewrites
 * responses, or a provider that only promises "some JSON" can all return
 * something that does not match. This validator is the guarantee. It is the
 * reason a malformed answer is a recoverable failure rather than data that
 * reaches a business service.
 *
 * It implements the subset of JSON Schema (Draft 2020-12) that Dukavo's own
 * schemas use — types, properties, required, additional properties, arrays,
 * string lengths and patterns, numeric bounds, enums, constants and the
 * composition keywords. It is deliberately dependency-free: adding a schema
 * library for this would be a dependency the application does not need.
 */
final class JsonSchemaValidator
{
    /**
     * Validate a decoded value.
     *
     * @param  array<string, mixed>  $schema
     */
    public function validate(mixed $value, array $schema): ValidationResult
    {
        $errors = [];

        $this->check($value, $schema, '$', $errors);

        return new ValidationResult($errors);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if ($schema === []) {
            return;
        }

        $this->checkType($value, $schema, $path, $errors);
        $this->checkEnum($value, $schema, $path, $errors);
        $this->checkComposition($value, $schema, $path, $errors);

        if (is_string($value)) {
            $this->checkString($value, $schema, $path, $errors);
        }

        if (is_int($value) || is_float($value)) {
            $this->checkNumber($value, $schema, $path, $errors);
        }

        if (is_array($value) && $this->isList($value)) {
            $this->checkArray($value, $schema, $path, $errors);
        } elseif (is_array($value)) {
            $this->checkObject($value, $schema, $path, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkType(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (! array_key_exists('type', $schema)) {
            return;
        }

        $expected = $schema['type'];
        $types = is_array($expected) ? array_values(array_filter($expected, 'is_string')) : [(string) $expected];

        foreach ($types as $type) {
            if ($this->matchesType($value, $type)) {
                return;
            }
        }

        $errors[] = sprintf(
            '%s: expected %s, got %s.',
            $path,
            implode(' or ', $types),
            $this->describe($value),
        );
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ! $this->isList($value),
            'array' => is_array($value) && $this->isList($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkEnum(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (array_key_exists('enum', $schema) && is_array($schema['enum'])) {
            foreach ($schema['enum'] as $candidate) {
                if ($this->same($value, $candidate)) {
                    return;
                }
            }

            $allowed = array_map(fn (mixed $candidate): string => $this->describe($candidate), $schema['enum']);

            $errors[] = sprintf('%s: must be one of %s.', $path, implode(', ', $allowed));
        }

        if (array_key_exists('const', $schema) && ! $this->same($value, $schema['const'])) {
            $errors[] = sprintf('%s: must be %s.', $path, $this->describe($schema['const']));
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkComposition(mixed $value, array $schema, string $path, array &$errors): void
    {
        foreach ((array) ($schema['allOf'] ?? []) as $sub) {
            if (is_array($sub)) {
                $this->check($value, $sub, $path, $errors);
            }
        }

        if (is_array($schema['anyOf'] ?? null) && $schema['anyOf'] !== []) {
            $matched = false;

            foreach ($schema['anyOf'] as $sub) {
                if (is_array($sub) && $this->check($value, $sub, $path, $errors)->isValid()) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                $errors[] = sprintf('%s: does not match any of the allowed shapes.', $path);
            }
        }

        if (is_array($schema['oneOf'] ?? null) && $schema['oneOf'] !== []) {
            $matches = 0;

            foreach ($schema['oneOf'] as $sub) {
                if (is_array($sub) && $this->check($value, $sub, $path, $errors)->isValid()) {
                    $matches++;
                }
            }

            if ($matches !== 1) {
                $errors[] = sprintf('%s: must match exactly one of the allowed shapes (matched %d).', $path, $matches);
            }
        }

        if (is_array($schema['not'] ?? null) && $this->check($value, $schema['not'], $path, $errors)->isValid()) {
            $errors[] = sprintf('%s: matches a forbidden shape.', $path);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkString(string $value, array $schema, string $path, array &$errors): void
    {
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = sprintf('%s: must be at least %d characters.', $path, (int) $schema['minLength']);
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = sprintf('%s: must be at most %d characters.', $path, (int) $schema['maxLength']);
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $matched = @preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value);

            if ($matched === 0) {
                $errors[] = sprintf('%s: does not match the expected format.', $path);
            }
        }

        if (isset($schema['format']) && is_string($schema['format']) && ! $this->matchesFormat($value, $schema['format'])) {
            $errors[] = sprintf('%s: is not a valid %s.', $path, $schema['format']);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkNumber(int|float $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < (float) $schema['minimum']) {
            $errors[] = sprintf('%s: must be greater than or equal to %s.', $path, $this->describe($schema['minimum']));
        }

        if (isset($schema['maximum']) && $value > (float) $schema['maximum']) {
            $errors[] = sprintf('%s: must be less than or equal to %s.', $path, $this->describe($schema['maximum']));
        }

        if (isset($schema['exclusiveMinimum']) && $value <= (float) $schema['exclusiveMinimum']) {
            $errors[] = sprintf('%s: must be greater than %s.', $path, $this->describe($schema['exclusiveMinimum']));
        }

        if (isset($schema['exclusiveMaximum']) && $value >= (float) $schema['exclusiveMaximum']) {
            $errors[] = sprintf('%s: must be less than %s.', $path, $this->describe($schema['exclusiveMaximum']));
        }

        if (isset($schema['multipleOf']) && (float) $schema['multipleOf'] > 0) {
            $quotient = $value / (float) $schema['multipleOf'];

            if (abs($quotient - round($quotient)) > 1e-9) {
                $errors[] = sprintf('%s: must be a multiple of %s.', $path, $this->describe($schema['multipleOf']));
            }
        }
    }

    /**
     * @param  list<mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkArray(array $value, array $schema, string $path, array &$errors): void
    {
        $count = count($value);

        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            $errors[] = sprintf('%s: must contain at least %d item(s).', $path, (int) $schema['minItems']);
        }

        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            $errors[] = sprintf('%s: must contain at most %d item(s).', $path, (int) $schema['maxItems']);
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];

            foreach ($value as $item) {
                $key = (string) json_encode($item);

                if (isset($seen[$key])) {
                    $errors[] = sprintf('%s: must not contain duplicate items.', $path);

                    break;
                }

                $seen[$key] = true;
            }
        }

        $items = $schema['items'] ?? null;

        if (is_array($items)) {
            foreach ($value as $index => $item) {
                $this->check($item, $items, sprintf('%s[%d]', $path, $index), $errors);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkObject(array $value, array $schema, string $path, array &$errors): void
    {
        $properties = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);

        foreach ($required as $key) {
            if (! is_string($key)) {
                continue;
            }

            if (! array_key_exists($key, $value)) {
                $errors[] = sprintf('%s: the "%s" field is required.', $path, $key);
            }
        }

        foreach ($value as $key => $item) {
            $key = (string) $key;

            if (isset($properties[$key]) && is_array($properties[$key])) {
                $this->check($item, $properties[$key], $path.'.'.$key, $errors);

                continue;
            }

            $additional = $schema['additionalProperties'] ?? true;

            if ($additional === false) {
                $errors[] = sprintf('%s: the "%s" field is not allowed.', $path, $key);
            } elseif (is_array($additional)) {
                $this->check($item, $additional, $path.'.'.$key, $errors);
            }
        }

        if (isset($schema['minProperties']) && count($value) < (int) $schema['minProperties']) {
            $errors[] = sprintf('%s: must have at least %d field(s).', $path, (int) $schema['minProperties']);
        }

        if (isset($schema['maxProperties']) && count($value) > (int) $schema['maxProperties']) {
            $errors[] = sprintf('%s: must have at most %d field(s).', $path, (int) $schema['maxProperties']);
        }
    }

    private function matchesFormat(string $value, string $format): bool
    {
        return match ($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date' => $this->isDate($value, 'Y-m-d'),
            'date-time' => $this->isDate($value, DATE_ATOM) || $this->isDate($value, 'Y-m-d\TH:i:sP'),
            default => true,
        };
    }

    private function isDate(string $value, string $format): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat($format, $value);

        return $parsed instanceof \DateTimeImmutable && $parsed->format($format) === $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    private function same(mixed $left, mixed $right): bool
    {
        if (is_int($left) && is_float($right)) {
            return (float) $left === $right;
        }

        if (is_float($left) && is_int($right)) {
            return $left === (float) $right;
        }

        return $left === $right;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.$value.'"',
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => 'array',
            default => (string) $value,
        };
    }
}
