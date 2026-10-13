<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsletterArticleStatus;
use App\Models\Concerns\Auditable;
use App\Support\HtmlSanitizer;
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
    'slug',
    'excerpt',
    'body',
    'cover_image_path',
    'cover_image_disk',
    'status',
    'published_at',
    'meta_title',
    'meta_description',
])]
class Newsletter extends Model
{
    /** @use HasFactory<NewsletterFactory> */
    use Auditable, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return [
            'title',
            'slug',
            'excerpt',
            'cover_image_path',
            'status',
            'published_at',
            'meta_title',
            'meta_description',
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
