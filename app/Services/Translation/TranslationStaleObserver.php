<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks existing translations stale when their source copy changes.
 *
 * The observer never deletes or rewrites a translation. A stale row keeps the
 * last approved text visible to shoppers and asks the merchant for a fresh
 * review; that is what makes editing a product name a safe action rather than
 * a silent replacement of every language.
 */
final class TranslationStaleObserver
{
    public function __construct(private readonly TranslationService $translations) {}

    public function updated(Model $model): void
    {
        $dirty = array_keys($model->getDirty());

        if ($dirty === []) {
            return;
        }

        $this->translations->markDirtySourcesStale($model, $dirty);
    }
}
