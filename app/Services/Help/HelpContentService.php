<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Enums\HelpArticleStatus;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use App\Services\Marketing\NewsletterContentService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Authoring Help Center articles.
 *
 * The same two data-integrity rules {@see NewsletterContentService}
 * enforces for newsletter editions, applied to guides:
 *
 *  1. `body` only ever reaches the database through the sanitiser, so no path
 *     can persist unsanitised markup.
 *  2. A cover is replaced by uploading the new file, saving the new path, and
 *     only then deleting the previous file, so a failed upload leaves the old
 *     cover intact instead of orphaning the article.
 *
 * Publication stamps `published_at` once, on the first publish; unpublishing
 * clears it so a reader surface never advertises a date for something it
 * cannot serve. Archiving keeps the row and its counters but removes it from
 * every reader surface; restoring returns the article to draft so an editor
 * re-reads it before it goes live again.
 */
class HelpContentService
{
    private const COVER_DISK = 'public';

    /** Inline body images must keep a stable URL for the article's lifetime. */
    private const CONTENT_DIRECTORY = 'help/content';

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated input, cover excluded.
     */
    public function create(User $author, array $attributes, ?UploadedFile $cover = null): HelpArticle
    {
        $attributes['body'] = $this->sanitizer->clean((string) ($attributes['body'] ?? ''));

        if (array_key_exists('body_ar', $attributes)) {
            $attributes['body_ar'] = $this->sanitizer->clean((string) ($attributes['body_ar'] ?? ''));
        }

        $status = isset($attributes['status'])
            ? HelpArticleStatus::from((string) $attributes['status'])
            : HelpArticleStatus::Draft;

        $attributes['status'] = $status;
        $attributes['published_at'] = $status === HelpArticleStatus::Published ? now() : null;
        $attributes['author_id'] = $author->getKey();

        $article = HelpArticle::query()->create($attributes);

        if (array_key_exists('tags', $attributes) && is_array($attributes['tags'])) {
            $article->tags()->sync($attributes['tags']);
        }

        if (array_key_exists('related_article_ids', $attributes) && is_array($attributes['related_article_ids'])) {
            $this->syncRelated($article, $attributes['related_article_ids']);
        }

        if ($cover !== null) {
            $this->replaceCover($article, $cover);
        }

        return $article->refresh()->loadMissing(['category', 'tags', 'relatedArticles']);
    }

    /**
     * @param  array<string, mixed>  $attributes  Validated input, cover excluded.
     */
    public function update(HelpArticle $article, array $attributes, ?UploadedFile $cover = null, bool $removeCover = false): HelpArticle
    {
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = $this->sanitizer->clean((string) ($attributes['body'] ?? ''));
        }

        if (array_key_exists('body_ar', $attributes)) {
            $attributes['body_ar'] = $this->sanitizer->clean((string) ($attributes['body_ar'] ?? ''));
        }

        if (array_key_exists('status', $attributes)) {
            $status = HelpArticleStatus::from((string) $attributes['status']);
            $attributes['status'] = $status;
            $attributes['published_at'] = $this->publishedAtFor($article, $status);
        }

        $article->fill($attributes)->save();

        if (array_key_exists('tags', $attributes) && is_array($attributes['tags'])) {
            $article->tags()->sync($attributes['tags']);
        }

        if (array_key_exists('related_article_ids', $attributes) && is_array($attributes['related_article_ids'])) {
            $this->syncRelated($article, $attributes['related_article_ids']);
        }

        if ($cover !== null) {
            $this->replaceCover($article, $cover);
        } elseif ($removeCover) {
            $this->removeCover($article);
        }

        return $article->refresh()->loadMissing(['category', 'tags']);
    }

    /**
     * Move an article to a given editorial status through an explicit action,
     * so the platform trail records "published" rather than a bare field edit.
     */
    public function transition(HelpArticle $article, HelpArticleStatus $status): HelpArticle
    {
        $article->forceFill([
            'status' => $status,
            'published_at' => $this->publishedAtFor($article, $status),
        ])->save();

        return $article->refresh()->loadMissing(['category', 'tags']);
    }

    /**
     * The `published_at` rule for a target status: stamped on first publish,
     * preserved while the article stays live, cleared when it leaves the
     * published state so no reader surface shows a stale date.
     */
    private function publishedAtFor(HelpArticle $article, HelpArticleStatus $status): ?string
    {
        if ($status === HelpArticleStatus::Published) {
            $existing = $article->published_at;

            return ($existing ?? now())->toIso8601String();
        }

        return null;
    }

    public function delete(HelpArticle $article): void
    {
        $this->deleteCoverFile($article);
        $article->delete();
    }

    /**
     * A category may only be deleted once it holds no articles, so tidying the
     * taxonomy can never silently destroy content. The database constraint is
     * the final arbiter; this check produces the honest error message.
     */
    public function deleteCategory(HelpCategory $category): void
    {
        if ($category->articles()->exists()) {
            abort(409, 'This category still has articles. Move them to another category, or archive the category instead.');
        }

        $category->delete();
    }

    /**
     * Store an inline image and return the reference the editor should embed.
     *
     * @return array{path: string, url: string}
     */
    public function storeInlineImage(UploadedFile $image): array
    {
        $path = $image->store(self::CONTENT_DIRECTORY, self::COVER_DISK);

        return [
            'path' => $path,
            'url' => Storage::disk(self::COVER_DISK)->url($path),
        ];
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function syncRelated(HelpArticle $article, array $articleIds): void
    {
        $unique = array_values(array_unique(array_map('intval', $articleIds)));
        $unique = array_values(array_filter($unique, static fn (int $id): bool => $id !== $article->getKey()));

        $article->relatedArticles()->sync($unique);
    }

    /**
     * Store the new cover, persist its path, and only then remove the old file.
     */
    private function replaceCover(HelpArticle $article, UploadedFile $cover): void
    {
        $previousPath = $article->cover_image_path;
        $previousDisk = $article->cover_image_disk ?? self::COVER_DISK;

        $path = $cover->store('help/'.$article->getKey(), self::COVER_DISK);

        $article->forceFill([
            'cover_image_path' => $path,
            'cover_image_disk' => self::COVER_DISK,
        ])->save();

        if ($previousPath !== null && $previousPath !== '') {
            Storage::disk($previousDisk)->delete($previousPath);
        }
    }

    private function removeCover(HelpArticle $article): void
    {
        if ($article->cover_image_path === null) {
            return;
        }

        $this->deleteCoverFile($article);

        $article->forceFill([
            'cover_image_path' => null,
            'cover_image_disk' => null,
        ])->save();
    }

    private function deleteCoverFile(HelpArticle $article): void
    {
        if ($article->cover_image_path === null || $article->cover_image_path === '') {
            return;
        }

        Storage::disk($article->cover_image_disk ?? self::COVER_DISK)->delete($article->cover_image_path);
    }
}
