<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\Auditable;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Request;

/**
 * Immutable trail of important changes.
 *
 * Rows are written through {@see AuditLog::write()} which pulls the acting user
 * and request metadata from the current context, so callers cannot forget to
 * attribute an event. Only allow-listed attributes ever reach `old_values` /
 * `new_values`, which is why no secret can end up here.
 */
#[Fillable(['tenant_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record an event. Called by the {@see Auditable}
     * trait and by services that need to log an action without a model hook.
     *
     * `tenant_id`/`user_id` fall back to the current request context, which is
     * why callers rarely need to pass them: platform level events (suspending
     * a tenant, for instance) can supply them explicitly.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function write(
        AuditAction $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $tenantId = null,
        ?int $userId = null,
    ): self {
        $context = app(TenantContext::class);

        return self::query()->create([
            'tenant_id' => $tenantId ?? $context->id(),
            'user_id' => $userId ?? auth()->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => self::currentIpAddress(),
            'user_agent' => self::currentUserAgent(),
            'created_at' => now(),
        ]);
    }

    private static function currentIpAddress(): ?string
    {
        // Console and queue contexts have no request; auditing still works.
        return app()->runningInConsole() ? null : Request::ip();
    }

    private static function currentUserAgent(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        return substr((string) Request::userAgent(), 0, 255);
    }
}
