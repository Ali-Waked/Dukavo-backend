<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\User;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the data a prompt is allowed to carry.
 *
 * Three rules govern everything that goes in:
 *
 *  1. Authorisation first. A fragment is only included when the actor may read
 *     the record it comes from, checked against Dukavo's own permission model.
 *  2. Tenant first. A record whose tenant does not match the active tenant is
 *     dropped even if it was somehow loaded — the model scope is the primary
 *     guard, this is the second one.
 *  3. Least data. Only the attributes a use case names are carried, personal
 *     data is minimised, and the whole block is bounded.
 *
 * The builder accepts records that were already loaded through the
 * application's own services. It never accepts a query, a table name or a
 * fragment of SQL, so a model can never widen its own reach by describing what
 * it wants to read.
 */
final class ContextBuilder
{
    /** @var list<ContextFragment> */
    private array $fragments = [];

    /** @var array<string, string> */
    private array $excluded = [];

    private int $budget;

    public function __construct(
        private readonly PromptGuard $prompts,
        private readonly TenantContext $tenants,
        private readonly PermissionService $permissions,
        private readonly ?User $actor,
    ) {
        $this->budget = (int) config('ai.context.max_chars', 12000);
    }

    /**
     * Add a plain value or a JSON-serialisable array.
     */
    public function add(string $label, array|string $content, ?int $maxChars = null): self
    {
        if ($this->fragments === [] && $this->excluded === [] && $this->budget <= 0) {
            $this->exclude($label, 'budget_exhausted');

            return $this;
        }

        $rendered = is_array($content)
            ? (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $content;

        $rendered = trim($rendered);

        if ($rendered === '') {
            $this->exclude($label, 'empty');

            return $this;
        }

        $limit = $maxChars ?? (int) config('ai.context.max_fragment_chars', 3000);
        $rendered = $this->prompts->truncate($rendered, $limit);

        if (mb_strlen($rendered) > $this->budget) {
            $this->exclude($label, 'budget_exhausted');

            return $this;
        }

        $this->budget -= mb_strlen($rendered);

        $this->fragments[] = new ContextFragment($label, $rendered);

        return $this;
    }

    /**
     * Add one record, projected onto the named attributes.
     *
     * @param  list<string>  $attributes
     */
    public function addRecord(string $label, Model $record, array $attributes, ?string $requiredPermission = null): self
    {
        if (! $this->maySee($label, $record, $requiredPermission)) {
            return $this;
        }

        return $this->add($label, $this->project($record, $attributes));
    }

    /**
     * Add a bounded collection of records, projected onto the named attributes.
     *
     * @param  iterable<Model>  $records
     * @param  list<string>  $attributes
     */
    public function addRecords(
        string $label,
        iterable $records,
        array $attributes,
        ?string $requiredPermission = null,
        ?int $limit = null,
    ): self {
        $limit ??= (int) config('ai.context.max_records', 25);
        $rows = [];
        $seen = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! $record instanceof Model) {
                continue;
            }

            if ($seen >= $limit) {
                break;
            }

            if (! $this->maySee($label, $record, $requiredPermission)) {
                $skipped++;

                continue;
            }

            $rows[] = $this->project($record, $attributes);
            $seen++;
        }

        if ($rows === []) {
            $this->exclude($label, $skipped > 0 ? 'not_authorised' : 'empty');

            return $this;
        }

        return $this->add($label, $rows);
    }

    /**
     * The assembled context.
     */
    public function build(): AiContext
    {
        return AiContext::build($this->prompts, $this->fragments, $this->excluded);
    }

    /**
     * Whether the actor may see this record at all.
     *
     * Both checks are refusals by default: an unknown tenant, an unknown
     * permission or a missing actor means the record is not included.
     */
    private function maySee(string $label, Model $record, ?string $requiredPermission): bool
    {
        $tenantId = $record->getAttribute('tenant_id');

        if ($tenantId !== null && (int) $tenantId !== $this->tenants->id()) {
            $this->exclude($label, 'tenant_mismatch');

            return false;
        }

        if ($requiredPermission === null) {
            return true;
        }

        if (! $this->actor instanceof User) {
            $this->exclude($label, 'not_authorised');

            return false;
        }

        if (! $this->permissions->hasPermission($this->actor, $requiredPermission, $this->tenants->tenant())) {
            $this->exclude($label, 'not_authorised');

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    private function project(Model $record, array $attributes): array
    {
        $projected = [];

        foreach ($attributes as $attribute) {
            $value = $record->getAttribute($attribute);

            $projected[$attribute] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $projected;
    }

    private function exclude(string $label, string $reason): void
    {
        $this->excluded[$label] ??= $reason;
    }
}
