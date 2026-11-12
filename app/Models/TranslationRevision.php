<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TranslationOrigin;
use App\Enums\TranslationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One historical state of a reviewable translation.
 *
 * Rows are append-only. The review ledger keeps the current copy; this history
 * lets the merchant see what an AI draft replaced and what was approved before
 * it, without turning the current row into a convoluted version log.
 */
#[Fillable([
    'translation_id',
    'tenant_id',
    'locale',
    'field',
    'old_text',
    'new_text',
    'old_status',
    'new_status',
    'origin',
    'changed_by',
])]
class TranslationRevision extends Model
{
    protected function casts(): array
    {
        return [
            'translation_id' => 'integer',
            'tenant_id' => 'integer',
            'changed_by' => 'integer',
            'old_status' => TranslationStatus::class,
            'new_status' => TranslationStatus::class,
            'origin' => TranslationOrigin::class,
        ];
    }

    /**
     * @return BelongsTo<Translation, $this>
     */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
