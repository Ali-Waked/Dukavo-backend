<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\TranslationStatus;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Approved-translation lookup for a translatable record.
 *
 * The ledger is consulted directly rather than through a relation so reading a
 * storefront page never triggers lazy loading. The result is cached on the
 * model instance for the lifetime of the request: a product page renders its
 * name, description and category from one query per locale, not one per field.
 *
 * @phpstan-require-extends Model
 */
trait HasTranslations
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $approvedTranslationCache = [];

    /**
     * @return MorphMany<Translation, $this>
     */
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * Every approved (or previously approved, now stale) translation for one
     * locale, keyed by field.
     *
     * @return array<string, string>
     */
    public function approvedTranslations(string $locale): array
    {
        $locale = strtolower(trim($locale));

        if (isset($this->approvedTranslationCache[$locale])) {
            return $this->approvedTranslationCache[$locale];
        }

        if (! $this->exists) {
            return $this->approvedTranslationCache[$locale] = [];
        }

        $rows = Translation::query()
            ->forRecord($this)
            ->forLocale($locale)
            ->whereNotNull('published_text')
            ->get(['field', 'published_text']);

        $map = [];

        foreach ($rows as $row) {
            $text = $row->published_text;

            if (is_string($text) && trim($text) !== '') {
                $map[(string) $row->field] = $text;
            }
        }

        return $this->approvedTranslationCache[$locale] = $map;
    }

    /**
     * The approved translation for one field, or the supplied fallback.
     */
    public function translatedValue(string $field, string $locale, ?string $fallback = null): ?string
    {
        $translations = $this->approvedTranslations($locale);

        return $translations[$field] ?? $fallback;
    }

    /**
     * Whether at least one approved field exists in the given locale.
     */
    public function hasApprovedTranslations(string $locale): bool
    {
        return $this->approvedTranslations($locale) !== [];
    }

    /**
     * Forget the request-local cache after a write.
     */
    public function forgetTranslationCache(): void
    {
        $this->approvedTranslationCache = [];
    }

    /**
     * Whether the record has any translation row in the given state.
     */
    public function hasTranslationStatus(TranslationStatus $status, ?string $locale = null): bool
    {
        return Translation::query()
            ->forRecord($this)
            ->when($locale !== null, fn ($query) => $query->forLocale($locale))
            ->where('status', $status)
            ->exists();
    }
}
