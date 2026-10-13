<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Row creation is delegated to {@see AuditLog::write()}, which already pulls the
 * acting user and request metadata from the current context. Services call this
 * instead of writing rows directly so that callers cannot forget to attribute an
 * event, and so console jobs and queued work produce the same records as HTTP
 * requests.
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Record an action against an optional subject.
     *
     * Never throws: losing an audit row must not roll back a business operation
     * that already succeeded. The failure is reported and swallowed.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(AuditAction $action, ?Model $subject = null, array $context = [], ?User $actor = null): ?AuditLog
    {
        try {
            return AuditLog::write(
                $action,
                $subject,
                $this->snapshot($context['old'] ?? null),
                $this->snapshot($context['new'] ?? null),
                $this->resolveTenantId($subject),
                $actor?->getKey() ?? $this->currentUserId(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Record only what actually changed, ignoring noise columns.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $ignored
     */
    public function recordChanges(
        AuditAction $action,
        Model $subject,
        array $before,
        array $after,
        array $ignored = ['updated_at', 'created_at', 'remember_token', 'password'],
        ?User $actor = null,
    ): ?AuditLog {
        $ignoredKeys = array_flip($ignored);
        $old = array_diff_key($before, $ignoredKeys);
        $new = array_diff_key($after, $ignoredKeys);

        $changedOld = [];
        $changedNew = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old) || $old[$key] !== $value) {
                $changedOld[$key] = $old[$key] ?? null;
                $changedNew[$key] = $value;
            }
        }

        if ($changedOld === []) {
            return null;
        }

        return $this->record($action, $subject, ['old' => $changedOld, 'new' => $changedNew], $actor);
    }

    /**
     * The tenant is taken from the subject when it is tenant scoped, so a
     * platform-level action performed against a tenant still lands in that
     * tenant's trail. Actions with no subject fall back to the active tenant.
     */
    private function resolveTenantId(?Model $subject): ?int
    {
        if ($subject !== null && $subject->getAttribute('tenant_id') !== null) {
            return (int) $subject->getAttribute('tenant_id');
        }

        return $this->tenants->id();
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User ? $user->getKey() : null;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function snapshot(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        return $this->normalise($values);
    }

    /**
     * Attribute values are stored as JSON, so enums, dates and models are
     * reduced to their scalar form before they reach the column.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(array $values): array
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            $normalised[$key] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof Model => $value->getKey(),
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                default => $value,
            };
        }

        return $normalised;
    }
}
