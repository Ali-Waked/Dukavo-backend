<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TranslationOrigin;
use App\Enums\TranslationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One reviewable translation of one entity field.
 *
 * This is the review ledger, not a second content store. The public read path
 * only ever consults approved (or previously approved, now stale) rows; the
 * merchant screen reads every row so a rejected draft is still visible and
 * explainable.
 *
 * It deliberately has no global tenant scope. Platform marketing content has
 * no tenant, and a global scope would make those rows invisible rather than
 * attributed. Tenant isolation is enforced by {@see self::visibleTo()} and by
 * the translation service/controller, which always resolve a tenant before
 * accepting an entity id.
 */
#[Fillable([
    'tenant_id',
    'store_id',
    'translatable_type',
    'translatable_id',
    'locale',
    'field',
    'source_locale',
    'source_text',
    'source_hash',
    'translated_text',
    'published_text',
    'ai_text',
    'status',
    'origin',
    'manually_edited',
    'provider',
    'model',
    'ai_usage_id',
    'generated_at',
    'reviewed_at',
    'reviewed_by',
    'rejection_reason',
    'metadata',
])]
class Translation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TranslationStatus::class,
            'origin' => TranslationOrigin::class,
            'manually_edited' => 'boolean',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
            'tenant_id' => 'integer',
            'store_id' => 'integer',
            'translatable_id' => 'integer',
            'reviewed_by' => 'integer',
            'ai_usage_id' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForRecord(Builder $query, Model|string $entity, ?int $id = null): void
    {
        if ($entity instanceof Model) {
            $id = (int) $entity->getKey();
            $entity = $entity->getMorphClass();
        }

        $query->where('translatable_type', $entity)->where('translatable_id', $id);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForLocale(Builder $query, string $locale): void
    {
        $query->where('locale', strtolower(trim($locale)));
    }

    /**
     * Restrict rows to what one workspace may see.
     *
     * A null tenant means the platform surface, which may only see rows that
     * belong to platform content (also null).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Tenant $tenant): void
    {
        if ($tenant === null) {
            $query->whereNull('tenant_id');

            return;
        }

        $query->where('tenant_id', $tenant->getKey());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->whereIn('status', [
            TranslationStatus::Approved->value,
            TranslationStatus::Stale->value,
        ]);
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    public function isLockedForRegeneration(): bool
    {
        return $this->manually_edited || $this->status === TranslationStatus::Approved;
    }
}
