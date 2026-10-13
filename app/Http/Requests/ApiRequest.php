<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Base class for every Dukavo API request.
 *
 * It exists for three reasons: a single place to read pagination input, a
 * single allow-list for sortable columns so a query string can never be
 * interpolated into SQL, and one consistent failure shape (the `422` envelope
 * produced by the exception handler).
 *
 * Authorization is deliberately *not* handled here. Permissions are enforced by
 * the `permission` middleware alias and record level rules by policies, which is
 * what the routing rules file documents.
 */
abstract class ApiRequest extends FormRequest
{
    protected const MAX_PER_PAGE = 100;

    /**
     * Sortable columns per resource. Anything not listed here is ignored.
     *
     * @return list<string>
     */
    protected function sortableColumns(): array
    {
        return ['id', 'created_at', 'updated_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function applySorting(Builder $query): Builder
    {
        $sort = (string) ($query['sort'] ?? $this->defaultSort());
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-+');

        if (! in_array($column, $this->sortableColumns(), true)) {
            $column = ltrim($this->defaultSort(), '-+');
            $direction = 'desc';
        }

        // A stable tie-breaker keeps pagination from skipping or repeating rows
        // when several rows share the sort value.
        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }

    protected function perPage(int $default = 15): int
    {
        return (int) min($this->integer('per_page', $default), static::MAX_PER_PAGE);
    }

    /**
     * A rule that accepts only values from a fixed set.
     *
     * @param  array<int, string>  $values
     */
    protected function oneOf(array $values): Rule
    {
        return Rule::in($values);
    }

    protected function tenantId(): ?int
    {
        return app(TenantContext::class)->id();
    }

    /**
     * An `exists` rule confined to the active tenant.
     *
     * `Rule::exists` runs a raw query and does not apply a model's global tenant
     * scope, so an unscoped rule would happily accept a foreign tenant's id and
     * turn an ownership mistake into a confusing 500 in the service. Scoping the
     * lookup to `tenant_id` rejects the value at validation time with a 422, and
     * when no tenant is resolved the rule matches nothing rather than everything.
     */
    protected function tenantExists(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $this->tenantId()));
    }

    /**
     * A `unique` rule confined to the active tenant, optionally ignoring a row
     * (the one being updated) so saving an unchanged value stays valid.
     */
    protected function tenantUnique(string $table, string $column, int|string|null $ignore = null): Unique
    {
        return Rule::unique($table, $column)
            ->where(fn ($query) => $query->where('tenant_id', $this->tenantId()))
            ->ignore($ignore);
    }

    /**
     * Normalise a submitted slug so "Summer Sale", "summer-sale" and
     * "summer_sale" cannot all claim to be distinct.
     */
    protected function prepareSlug(string $field = 'slug'): void
    {
        if (! $this->filled($field)) {
            return;
        }

        $this->merge([$field => Str::slug($this->string($field)->toString())]);
    }
}
