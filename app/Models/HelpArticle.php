<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleVisibility;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use App\Services\Help\HelpCenterService;
use App\Support\LocalizedText;
use Database\Factories\HelpArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One Help Center article: a guide written by the platform team.
 *
 * Platform-level (no `tenant_id`). `body` is sanitised HTML — the authoring
 * service runs it through {@see HtmlSanitizer} on write, mirroring the
 * newsletter body, so what is stored is safe to render. Localisation follows
 * the newsletter pattern: canonical English columns, legacy `_ar` columns,
 * and every other locale served from the approved translation ledger.
 *
 * Only `published` articles inside the caller's allowed visibility set are
 * ever returned by a reader surface. View and helpfulness counters are
 * denormalised on the row so the article list renders without aggregate
 * queries.
 */
#[Fillable([
    'category_id',
    'author_id',
    'title',
    'title_ar',
    'slug',
    'excerpt',
    'excerpt_ar',
    'body',
    'body_ar',
    'cover_image_path',
    'cover_image_disk',
    'video_url',
    'status',
    'visibility',
    'is_featured',
    'sort_order',
    'published_at',
    'meta_title',
    'meta_title_ar',
    'meta_description',
    'meta_description_ar',
    'meta_keywords',
])]
class HelpArticle extends Model
{
    /** @use HasFactory<HelpArticleFactory> */
    use Auditable, HasFactory, HasTranslations;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        // The body is excluded for the same reason a newsletter's is: it is a
        // large document, and the trail records scalar state changes.
        return [
            'category_id',
            'title',
            'title_ar',
            'slug',
            'status',
            'visibility',
            'video_url',
            'cover_image_path',
            'is_featured',
            'sort_order',
            'published_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => HelpArticleStatus::class,
            'visibility' => HelpArticleVisibility::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'view_count' => 'integer',
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
            'localized_slugs' => 'array',
        ];
    }

    /**
     * @return BelongsTo<HelpCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<HelpTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(HelpTag::class, 'help_article_tag', 'article_id', 'tag_id');
    }

    /**
     * @return BelongsToMany<self, $this>
     */
    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'help_article_related', 'article_id', 'related_article_id')
            ->withPivot('sort_order')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<HelpArticleFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(HelpArticleFeedback::class, 'article_id');
    }

    /**
     * The public URL of the cover, derived from where the file actually lives.
     */
    public function coverImageUrl(): ?string
    {
        if ($this->cover_image_path === null || $this->cover_image_path === '') {
            return null;
        }

        return Storage::disk($this->cover_image_disk ?? 'public')->url($this->cover_image_path);
    }

    /**
     * Estimated reading time in whole minutes, at roughly 200 words per
     * minute, for the copy the reader actually sees.
     */
    public function readingMinutesFor(?string $body): int
    {
        $text = html_entity_decode($body ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if ($text === '') {
            return 1;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return max(1, (int) ceil(count($words) / 200));
    }

    /** The slug to use in the requested language. */
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
     * The language the article is actually served in, so the client can tell
     * a reader when they are seeing a fallback.
     */
    public function resolvedLocale(string $locale): string
    {
        if ($locale === 'ar' && $this->hasLegacyArabicCopy()) {
            return 'ar';
        }

        if ($this->hasApprovedTranslations($locale)) {
            return $locale;
        }

        return 'en';
    }

    private function hasLegacyArabicCopy(): bool
    {
        foreach (['title_ar', 'excerpt_ar', 'body_ar'] as $field) {
            $value = $this->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /** The best available title for the requested locale. */
    public function localizedTitle(string $locale): string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('title', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return (string) LocalizedText::pick($this->title, $this->title_ar, $locale);
    }

    /** The best available excerpt for the requested locale. */
    public function localizedExcerpt(string $locale): ?string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('excerpt', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return LocalizedText::pick($this->excerpt, $this->excerpt_ar, $locale);
    }

    /** The best available sanitised body for the requested locale. */
    public function localizedBody(string $locale): string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('body', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return (string) LocalizedText::pick($this->body, $this->body_ar, $locale);
    }

    /** The best available meta title for the requested locale. */
    public function localizedMetaTitle(string $locale): ?string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('meta_title', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return LocalizedText::pick($this->meta_title, $this->meta_title_ar, $locale);
    }

    /** The best available meta description for the requested locale. */
    public function localizedMetaDescription(string $locale): ?string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('meta_description', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return LocalizedText::pick($this->meta_description, $this->meta_description_ar, $locale);
    }

    /**
     * Persist an approved localized slug, refusing a collision.
     *
     * A localized slug that collides with another article's canonical or
     * localized slug would make one URL resolve to two articles, so the
     * approval is refused rather than silently overwriting the other URL.
     */
    public function applyLocalizedSlug(string $locale, string $value): void
    {
        $locale = strtolower(trim($locale));
        $slug = Str::slug($value, '-', $locale) ?: Str::slug($value);
        $slug = mb_substr($slug, 0, 180);

        if ($slug === '') {
            return;
        }

        $conflict = static::query()
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) use ($slug, $locale): void {
                $query->where('slug', $slug)
                    ->orWhere('localized_slugs->'.$locale, $slug);
            })
            ->exists();

        if ($conflict) {
            return;
        }

        $slugs = is_array($this->localized_slugs) ? $this->localized_slugs : [];
        $slugs[$locale] = $slug;

        $this->forceFill(['localized_slugs' => $slugs])->saveQuietly();
    }

    /**
     * The share of readers who marked the article helpful, or null before the
     * first vote. Served as a ratio, not a percentage, so the client formats it.
     */
    public function helpfulnessRatio(): ?float
    {
        $votes = $this->helpful_count + $this->unhelpful_count;

        if ($votes === 0) {
            return null;
        }

        return round($this->helpful_count / $votes, 3);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', HelpArticleStatus::Published);
    }

    /**
     * Restrict to articles the given visibility tiers allow. The list is
     * computed by {@see HelpCenterService::allowedVisibilities()}
     * from the viewer, never taken from the request.
     *
     * @param  Builder<self>  $query
     * @param  list<HelpArticleVisibility>  $visibilities
     */
    #[Scope]
    protected function visibleTo(Builder $query, array $visibilities): void
    {
        $query->whereIn('visibility', array_map(
            static fn (HelpArticleVisibility $visibility): string => $visibility->value,
            $visibilities,
        ));
    }

    /**
     * Resolve a reader-facing slug: the canonical one or any approved
     * localized one, so a translated URL opens the same article. The JSON
     * path lookup stays precise because it is built per supported locale
     * rather than as a loose text match.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forSlug(Builder $query, string $slug): void
    {
        $query->where(function (Builder $inner) use ($slug): void {
            $inner->where('slug', $slug);

            foreach ((array) config('dukavo.locales.supported', ['en']) as $locale) {
                $inner->orWhere('localized_slugs->'.strtolower(trim((string) $locale)), $slug);
            }
        });
    }

    /**
     * The reader order within a category: the admin's chosen position first,
     * with a stable id tie-break so prev/next navigation is deterministic.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The publication order for "recently updated" surfaces.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function newest(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }
}
