<?php

declare(strict_types=1);

namespace App\Services\Ai\Validation;

/**
 * The outcome of resolving identifiers a model proposed.
 *
 * `rows` holds only what the application itself vouches for. `rejected` holds
 * the identifiers that were asked for but do not exist here — because they
 * never existed, or because they belong to another tenant. Both halves matter:
 * the first is what may be used, the second is what a caller must be told was
 * dropped.
 */
final readonly class ReferenceResolution
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $rejected
     */
    public function __construct(
        private array $rows,
        private array $rejected,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The identifiers that were resolved, in the order the database returned them.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            array_filter($this->rows, static fn (array $row): bool => isset($row['id'])),
        );
    }

    /**
     * @return list<string>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Whether every requested identifier was resolved.
     */
    public function isComplete(): bool
    {
        return $this->rejected === [];
    }
}
