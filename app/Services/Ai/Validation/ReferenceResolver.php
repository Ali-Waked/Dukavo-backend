<?php

declare(strict_types=1);

namespace App\Services\Ai\Validation;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns identifiers a model proposed into values the application vouches for.
 *
 * A model is good at writing prose and unreliable at naming records. It may
 * invent a product id, quote a price from memory, name a customer that does
 * not exist, or — deliberately — name one that belongs to somebody else. None
 * of that may reach a business action.
 *
 * So every identifier the model proposes is resolved again, here, through the
 * application's own tenant-scoped queries. What comes back is either a record
 * this tenant really has, or nothing. Identifiers that cannot be resolved are
 * reported as rejected rather than silently passed on.
 *
 * The resolver queries through Eloquent models, so the tenant scope applies
 * automatically. There is no path here by which a caller can express a query,
 * a table or a condition: the model chooses *which* records to talk about, the
 * application decides *whether they exist*.
 */
final class ReferenceResolver
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Resolve a list of identifiers against a tenant-scoped model.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int|string>  $ids
     * @param  list<string>  $attributes  The authoritative columns to return.
     */
    public function resolve(string $modelClass, array $ids, array $attributes = ['id']): ReferenceResolution
    {
        $wanted = [];
        $rejected = [];

        foreach ($ids as $id) {
            $normalised = $this->normaliseId($id);

            if ($normalised === null) {
                $rejected[] = (string) $id;

                continue;
            }

            $wanted[$normalised] = true;
        }

        if ($wanted === []) {
            return new ReferenceResolution([], $rejected);
        }

        $columns = array_values(array_unique(array_merge(['id'], $attributes)));

        // The tenant scope is applied by the model itself, so a record from
        // another tenant is simply not found here.
        $found = $modelClass::query()
            ->whereIn('id', array_keys($wanted))
            ->get($columns);

        $rows = [];

        foreach ($found as $record) {
            $row = [];

            foreach ($columns as $column) {
                $value = $record->getAttribute($column);
                $row[$column] = $value instanceof \BackedEnum ? $value->value : $value;
            }

            $rows[] = $row;
        }

        $resolvedIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        foreach (array_keys($wanted) as $id) {
            if (! in_array($id, $resolvedIds, true)) {
                $rejected[] = (string) $id;
            }
        }

        return new ReferenceResolution($rows, array_values(array_unique($rejected)));
    }

    /**
     * Rewrite the reference fields of a payload using authoritative values.
     *
     * The specification names, per payload field, the model that owns the
     * identifier and the columns that may be read from it. A field whose
     * identifiers cannot all be resolved is emptied rather than passed on: a
     * half-resolved reference is worse than none, because it looks real.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{model: class-string<Model>, attributes?: list<string>, many?: bool, overwrite?: list<string>}>  $spec
     * @return array{payload: array<string, mixed>, resolutions: array<string, ReferenceResolution>}
     */
    public function hydrate(array $payload, array $spec): array
    {
        $resolutions = [];

        foreach ($spec as $field => $definition) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $many = (bool) ($definition['many'] ?? false);
            $attributes = (array) ($definition['attributes'] ?? ['id']);
            $modelClass = $definition['model'];

            $raw = $payload[$field];

            if ($many) {
                $ids = is_array($raw) ? $raw : [$raw];
            } else {
                $ids = is_array($raw) ? array_values($raw) : [$raw];
            }

            $resolution = $this->resolve($modelClass, $ids, $attributes);
            $resolutions[$field] = $resolution;

            if ($many) {
                $payload[$field] = $resolution->rows();

                continue;
            }

            $row = $resolution->rows()[0] ?? null;

            if ($row === null) {
                unset($payload[$field]);
            } else {
                $payload[$field] = $row;

                foreach ((array) ($definition['overwrite'] ?? []) as $attribute) {
                    if (array_key_exists($attribute, $row)) {
                        $payload[$attribute] = $row[$attribute];
                    }
                }
            }
        }

        return ['payload' => $payload, 'resolutions' => $resolutions];
    }

    /**
     * Whether a tenant is currently resolved.
     *
     * Resolving a reference without a tenant would fall back to the model's
     * own scope, which matches nothing — so this is a guard against silently
     * returning an empty result instead of an error.
     */
    public function hasTenant(): bool
    {
        return $this->tenants->has();
    }

    private function normaliseId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if (is_string($id) && ctype_digit($id)) {
            $value = (int) $id;

            return $value > 0 ? $value : null;
        }

        return null;
    }
}
