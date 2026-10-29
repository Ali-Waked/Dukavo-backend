<?php

declare(strict_types=1);

namespace App\Services\Ai\Validation;

/**
 * The outcome of validating a model's answer against its schema.
 *
 * Errors are human-readable and path-qualified because they are fed straight
 * back to the model on a repair attempt, and because they are what an operator
 * sees when a provider misbehaves.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(private array $errors) {}

    /**
     * @param  list<string>  $errors
     */
    public static function valid(): self
    {
        return new self([]);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * A single, bounded description of what went wrong.
     */
    public function summary(int $max = 5): string
    {
        if ($this->isValid()) {
            return 'The response matched the requested schema.';
        }

        $shown = array_slice($this->errors, 0, $max);
        $remaining = count($this->errors) - count($shown);

        if ($remaining > 0) {
            $shown[] = sprintf('…and %d more problem(s).', $remaining);
        }

        return implode(' ', $shown);
    }
}
