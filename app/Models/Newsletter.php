<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsletterArticleStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use App\Support\HtmlSanitizer;
use App\Support\LocalizedText;
use Database\Factories\NewsletterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A newsletter edition: the long-form article Dukavo publishes to its audience.
 *
 * Platform-level (no `tenant_id`). `body` is sanitised HTML — the controller
 * runs it through {@see HtmlSanitizer} on write, so what is stored
 * is already safe to render as markup. The cover is referenced by a filesystem
 * path and disk, never a baked URL, so the public URL is derived on read.
 *
 * Published copy is public content the platform team is accountable for, so the
 * edition's identity and publication state are auditable. The body is excluded
 * from the audit snapshot for the same reason a page's JSON sections are: it is
 * large, and the trail records scalar state changes, not document bodies.
 */
#[Fillable([
    'title',
    'title_ar',
    'slug',
    'excerpt',
    'excerpt_ar',
    'body',
    'body_ar',
    'cover_image_path',
    'cover_image_disk',
    'status',
    'published_at',
    'meta_title',
    'meta_title_ar',
    'meta_description',
    'meta_description_ar',
])]
class Newsletter extends Model
{
    /** @use HasFactory<NewsletterFactory> */
    use Auditable, HasFactory, HasTranslations;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return [
            'title',
            'title_ar',
            'slug',
            'excerpt',
            'excerpt_ar',
            'cover_image_path',
            'status',
            'published_at',
            'meta_title',
            'meta_title_ar',
            'meta_description',
            'meta_description_ar',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => NewsletterArticleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * The public URL of the cover, derived from where the file actually lives.
     * Null when no cover has been uploaded.
     */
    public function coverImageUrl(): ?string
    {
        if ($this->cover_image_path === null || $this->cover_image_path === '') {
            return null;
        }

        return Storage::disk($this->cover_image_disk ?? 'public')->url($this->cover_image_path);
    }

    /**
     * Estimated reading time in whole minutes, at roughly 200 words per minute.
     * The body is sanitised HTML, so tags and entities are stripped before
     * counting; an empty edition still reports one minute rather than zero.
     */
    public function readingMinutes(): int
    {
        return $this->readingMinutesFor($this->body);
    }

    /**
     * Reading time for a specific body, so a translated article reports the
     * length of the copy the visitor actually sees.
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

    /**
     * The language the edition is actually served in.
     *
     * Legacy Arabic copy is stored in `*_ar` columns rather than the ledger, so
     * both sources count before the edition degrades to its English original.
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
        foreach (['title_ar', 'excerpt_ar', 'body_ar', 'meta_title_ar', 'meta_description_ar'] as $field) {
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
    public function localizedBody(string $locale): ?string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('body', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return LocalizedText::pick($this->body, $this->body_ar, $locale);
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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', NewsletterArticleStatus::Published);
    }

    /**
     * The archive order: newest publication first, with a stable id tie-break
     * so pagination never repeats or skips an edition.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }
}
