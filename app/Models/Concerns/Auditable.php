<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Audit trail helper.
 *
 * Models opt in by listing the attributes that may be recorded. The list is an
 * explicit allow-list, so a password, access token or WhatsApp credential can
 * never reach an audit entry — not even by accident.
 */
trait Auditable
{
    /**
     * Attributes whose values may be written to the audit log.
     *
     * @return list<string>
     */
    abstract protected function auditAttributes(): array;

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditSnapshot(): array
    {
        return collect($this->auditAttributes())
            ->mapWithKeys(fn (string $attribute): array => [$attribute => $this->getAttribute($attribute)])
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function recordAuditEvent(AuditAction $action, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        return AuditLog::write($action, $this, $oldValues, $newValues);
    }

    /**
     * Record the lifecycle events of the model.
     *
     * Updates store only the allow-listed attributes whose values actually
     * changed, which keeps the trail readable and stops `updated_at` churn from
     * filling it with meaningless entries.
     */
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->recordAuditEvent(AuditAction::Created, [], $model->auditSnapshot()));

        static::updated(function (Model $model): void {
            $new = $model->auditSnapshot();
            $old = [];

            foreach (array_keys($new) as $attribute) {
                $before = $model->getOriginal($attribute);
                $before = $before instanceof BackedEnum ? $before->value : $before;
                $after = $new[$attribute] instanceof BackedEnum ? $new[$attribute]->value : $new[$attribute];

                if ((string) $before !== (string) $after) {
                    $old[$attribute] = $before;
                }
            }

            if ($old === []) {
                return;
            }

            $model->recordAuditEvent(AuditAction::Updated, $old, $new);
        });

        static::deleted(fn (Model $model) => $model->recordAuditEvent(AuditAction::Deleted, $model->auditSnapshot(), []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->recordAuditEvent(AuditAction::Restored, [], $model->auditSnapshot()));
        }
    }
}
