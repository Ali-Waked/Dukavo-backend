<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A sellable catalogue item.
 *
 * Price lives on the product (the "from" price shown in listings) and on each
 * variant (the price actually charged). Every product is guaranteed at least
 * one variant so inventory and orders can always reference a concrete unit.
 *
 * The canonical columns are the source language. Approved translations live in
 * the shared review ledger and are read through {@see HasTranslations}; the
 * product is never duplicated per language, so SKU, price, stock and images
 * have exactly one owner.
 */
#[Fillable([
    'tenant_id',
    'store_id',
    'category_id',
    'name',
    'slug',
    'sku',
    'description',
    'short_description',
    'highlights',
    'features',
    'specifications',
    'tags',
    'seo_title',
    'seo_description',
    'seo_keywords',
    'localized_slugs',
    'status',
    'price',
    'cost',
    'currency',
    'is_taxable',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasTranslations, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'slug', 'sku', 'status', 'price', 'cost', 'is_taxable'];
    }

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'price' => 'decimal:4',
            'cost' => 'decimal:4',
            'is_taxable' => 'boolean',
            'highlights' => 'array',
            'features' => 'array',
            'specifications' => 'array',
            'tags' => 'array',
            'localized_slugs' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * @return HasMany<Inventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The variant an order should reference when the customer did not pick one.
     */
    public function defaultVariant(): ?ProductVariant
    {
        return $this->variants->firstWhere('is_default', true) ?? $this->variants->first();
    }

    public function isSellable(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    /** The best available product name for the requested locale. */
    public function localizedName(string $locale): string
    {
        return (string) $this->translatedValueWithFallback(
            'name',
            $locale,
            $this->storeDefaultLocale(),
            (string) $this->name,
        );
    }

    /** The best available short description, or null when none exists. */
    public function localizedShortDescription(string $locale): ?string
    {
        return $this->translatedValueWithFallback('short_description', $locale, $this->storeDefaultLocale(), $this->short_description);
    }

    /** The best available description, or the canonical value when untranslated. */
    public function localizedDescription(string $locale): ?string
    {
        return $this->translatedValueWithFallback('description', $locale, $this->storeDefaultLocale(), $this->description);
    }

    /**
     * @return list<string>
     */
    public function localizedHighlights(string $locale): array
    {
        return $this->localizedLines('highlights', $locale, $this->stringList($this->highlights));
    }

    /**
     * @return list<string>
     */
    public function localizedFeatures(string $locale): array
    {
        return $this->localizedLines('features', $locale, $this->stringList($this->features));
    }

    /**
     * @return list<string>
     */
    public function localizedTags(string $locale): array
    {
        $translated = $this->translatedValueWithFallback('tags', $locale, $this->storeDefaultLocale());

        if ($translated === null || trim($translated) === '') {
            return $this->stringList($this->tags);
        }

        return array_values(array_filter(array_map(
            static fn (string $tag): string => trim($tag),
            preg_split('/\s*,\s*/u', $translated) ?: [],
        ), static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function localizedSpecifications(string $locale): array
    {
        $canonical = $this->specificationList($this->specifications);
        $translated = $this->translatedValueWithFallback('specifications', $locale, $this->storeDefaultLocale());

        if ($translated === null || trim($translated) === '') {
            return $canonical;
        }

        $parsed = [];

        foreach (preg_split('/\r\n|\r|\n/u', $translated) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $position = mb_strpos($line, ':');

            $parsed[] = $position === false
                ? ['label' => $line, 'value' => '']
                : ['label' => trim(mb_substr($line, 0, $position)), 'value' => trim(mb_substr($line, $position + 1))];
        }

        return $parsed === [] ? $canonical : $parsed;
    }

    public function localizedSeoTitle(string $locale): ?string
    {
        return $this->translatedValueWithFallback('seo_title', $locale, $this->storeDefaultLocale(), $this->seo_title);
    }

    public function localizedSeoDescription(string $locale): ?string
    {
        return $this->translatedValueWithFallback('seo_description', $locale, $this->storeDefaultLocale(), $this->seo_description);
    }

    public function localizedSeoKeywords(string $locale): ?string
    {
        return $this->translatedValueWithFallback('seo_keywords', $locale, $this->storeDefaultLocale(), $this->seo_keywords);
    }

    /**
     * The slug to use in the requested language.
     *
     * A slug is URL identity, not prose, so it never borrows another language's
     * slug: an Arabic URL must not silently become a French one. If this locale
     * has no approved slug the canonical slug is used, which always resolves.
     */
    public function localizedSlug(string $locale): string
    {
        $locale = strtolower(trim($locale));
        $slugs = is_array($this->localized_slugs) ? $this->localized_slugs : [];
        $stored = $slugs[$locale] ?? null;

        if (is_string($stored) && trim($stored) !== '') {
            return $stored;
        }

        return (string) $this->translatedValue('slug', $locale, (string) $this->slug);
    }

    /**
     * Every public-facing field in one language, with canonical fallbacks.
     *
     * @return array<string, mixed>
     */
    public function localizedPayload(string $locale): array
    {
        return [
            'name' => $this->localizedName($locale),
            'slug' => $this->localizedSlug($locale),
            'short_description' => $this->localizedShortDescription($locale),
            'description' => $this->localizedDescription($locale),
            'highlights' => $this->localizedHighlights($locale),
            'features' => $this->localizedFeatures($locale),
            'specifications' => $this->localizedSpecifications($locale),
            'tags' => $this->localizedTags($locale),
            'seo_title' => $this->localizedSeoTitle($locale),
            'seo_description' => $this->localizedSeoDescription($locale),
            'seo_keywords' => $this->localizedSeoKeywords($locale),
        ];
    }

    /**
     * The canonical source text the translation assistant sends to a model.
     *
     * Lists are newline-delimited and tags are comma-separated so the ledger
     * keeps one reviewable row per field instead of a JSON blob a reviewer
     * cannot easily edit.
     */
    public function translationSource(string $field): ?string
    {
        $value = match ($field) {
            'highlights', 'features' => implode("\n", $this->stringList($this->getAttribute($field))),
            'tags' => implode(', ', $this->stringList($this->tags)),
            'specifications' => implode("\n", array_map(
                static fn (array $row): string => trim($row['label'].': '.$row['value']),
                $this->specificationList($this->specifications),
            )),
            default => $this->getAttribute($field),
        };

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * Persist an approved localized slug, refusing a collision.
     *
     * The check spans canonical slugs and every other locale's slug in the
     * tenant. A localized slug that collides with an existing product would make
     * one URL resolve to two products, so the approval is refused rather than
     * silently overwriting the other product's URL.
     */
    public function applyLocalizedSlug(string $locale, string $value): void
    {
        $locale = strtolower(trim($locale));
        $slug = Str::slug($value, '-', $locale) ?: Str::slug($value);

        if ($slug === '') {
            throw BusinessRuleViolation::because(
                'The translated slug is empty. Choose a slug that contains letters or numbers.',
                ['locale' => $locale],
                'product_slug_invalid',
            );
        }

        $slug = mb_substr($slug, 0, 180);

        $conflict = static::query()
            ->withTrashed()
            ->where('tenant_id', $this->tenant_id)
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) use ($slug, $locale): void {
                $query->where('slug', $slug)
                    ->orWhere('localized_slugs->'.$locale, $slug);
            })
            ->exists();

        if ($conflict) {
            throw BusinessRuleViolation::because(
                'This localized slug is already used by another product.',
                ['locale' => $locale, 'slug' => $slug],
                'product_slug_taken',
            );
        }

        $slugs = is_array($this->localized_slugs) ? $this->localized_slugs : [];
        $slugs[$locale] = $slug;

        $this->forceFill(['localized_slugs' => $slugs])->saveQuietly();
    }

    /** The store's default language, or the platform default when unavailable. */
    public function storeDefaultLocale(): string
    {
        $store = $this->relationLoaded('store') ? $this->getRelation('store') : null;
        $locale = $store instanceof Store ? $store->default_locale : null;

        return is_string($locale) && trim($locale) !== ''
            ? strtolower(trim($locale))
            : (string) config('dukavo.locales.default', 'en');
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function localizedLines(string $field, string $locale, array $fallback): array
    {
        $translated = $this->translatedValueWithFallback($field, $locale, $this->storeDefaultLocale());

        if ($translated === null || trim($translated) === '') {
            return $fallback;
        }

        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\r\n|\r|\n/u', $translated) ?: [],
        ), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? $fallback : $lines;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function specificationList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = isset($item['label']) && is_string($item['label']) ? trim($item['label']) : '';
            $itemValue = isset($item['value']) && is_string($item['value']) ? trim($item['value']) : '';

            if ($label === '' && $itemValue === '') {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $itemValue];
        }

        return $rows;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', ProductStatus::Active);
    }

    /**
     * Free-text search across the canonical copy, every approved translation
     * and the operational identifiers.
     *
     * SKU and barcode live on variants and are language-independent, but a
     * shopper may type either from a label, so they are searched too. A
     * `whereHas` subquery is used instead of a join so a product matching both
     * its name and a variant still appears once.
     */
    #[Scope]
    protected function search(Builder $query, ?string $term, ?string $locale = null): void
    {
        $query->when($term, function (Builder $builder, string $value) use ($locale): void {
            $escaped = addcslashes($value, '%_\\');
            $like = "%{$escaped}%";

            $builder->where(function (Builder $inner) use ($like, $locale): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('short_description', 'like', $like)
                    ->orWhere('seo_keywords', 'like', $like)
                    ->orWhereHas('variants', function (Builder $variant) use ($like): void {
                        $variant->where('sku', 'like', $like)
                            ->orWhere('barcode', 'like', $like)
                            ->orWhere('name', 'like', $like);
                    })
                    ->orWhereHas('translations', function (Builder $translation) use ($like, $locale): void {
                        $translation->whereNotNull('published_text')
                            ->whereIn('field', ['name', 'short_description', 'description', 'tags', 'highlights', 'features', 'specifications'])
                            ->when($locale !== null, fn (Builder $scoped) => $scoped->where('locale', strtolower($locale)))
                            ->where('published_text', 'like', $like);
                    });
            });
        });
    }
}
