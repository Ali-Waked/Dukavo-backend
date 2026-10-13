<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\NewsletterArticleStatus;
use App\Models\Newsletter;
use App\Support\HtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Authoring newsletter editions.
 *
 * Two rules are enforced here rather than in the controllers, because both are
 * data-integrity concerns that a second caller could otherwise forget:
 *
 *  1. `body` only ever reaches the database through the sanitiser, so no path
 *     can persist unsanitised markup.
 *  2. A cover is replaced by uploading the new file, saving the new path, and
 *     only then deleting the previous file. A failed upload therefore leaves the
 *     old cover intact instead of orphaning the edition.
 *
 * Nothing here sends email. Publishing an edition makes it visible on the public
 * archive; delivery to subscribers is a separate concern that does not exist yet.
 */
class NewsletterContentService
{
    private const COVER_DISK = 'public';

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated input, cover excluded.
     */
    public function create(array $attributes, ?UploadedFile $cover = null): Newsletter
    {
        $attributes['body'] = $this->sanitizer->clean((string) ($attributes['body'] ?? ''));

        $status = isset($attributes['status'])
            ? NewsletterArticleStatus::from((string) $attributes['status'])
            : NewsletterArticleStatus::Draft;

        $attributes['status'] = $status;
        $attributes['published_at'] = $status === NewsletterArticleStatus::Published ? now() : null;

        $newsletter = Newsletter::query()->create($attributes);

        if ($cover !== null) {
            $this->replaceCover($newsletter, $cover);
        }

        return $newsletter;
    }

    /**
     * @param  array<string, mixed>  $attributes  Validated input, cover excluded.
     */
    public function update(Newsletter $newsletter, array $attributes, ?UploadedFile $cover = null, bool $removeCover = false): Newsletter
    {
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = $this->sanitizer->clean((string) $attributes['body']);
        }

        if (array_key_exists('status', $attributes)) {
            $status = NewsletterArticleStatus::from((string) $attributes['status']);
            $attributes['status'] = $status;

            // Publish stamps the first live date once; editing a live edition
            // must not make it look freshly published. Drafting clears it so the
            // public archive never shows an unpublished date.
            if ($status === NewsletterArticleStatus::Published) {
                $attributes['published_at'] = $newsletter->published_at ?? now();
            } else {
                $attributes['published_at'] = null;
            }
        }

        $newsletter->fill($attributes)->save();

        if ($cover !== null) {
            $this->replaceCover($newsletter, $cover);
        } elseif ($removeCover) {
            $this->removeCover($newsletter);
        }

        return $newsletter;
    }

    public function delete(Newsletter $newsletter): void
    {
        $this->deleteCoverFile($newsletter);
        $newsletter->delete();
    }

    /**
     * Store the new cover, persist its path, and only then remove the old file.
     * A failure at any earlier step leaves the edition pointing at its previous,
     * still-present image.
     */
    private function replaceCover(Newsletter $newsletter, UploadedFile $cover): void
    {
        $previousPath = $newsletter->cover_image_path;
        $previousDisk = $newsletter->cover_image_disk ?? self::COVER_DISK;

        $path = $cover->store('newsletters/'.$newsletter->getKey(), self::COVER_DISK);

        $newsletter->forceFill([
            'cover_image_path' => $path,
            'cover_image_disk' => self::COVER_DISK,
        ])->save();

        if ($previousPath !== null && $previousPath !== '') {
            Storage::disk($previousDisk)->delete($previousPath);
        }
    }

    private function removeCover(Newsletter $newsletter): void
    {
        if ($newsletter->cover_image_path === null) {
            return;
        }

        $this->deleteCoverFile($newsletter);

        $newsletter->forceFill([
            'cover_image_path' => null,
            'cover_image_disk' => null,
        ])->save();
    }

    private function deleteCoverFile(Newsletter $newsletter): void
    {
        if ($newsletter->cover_image_path === null || $newsletter->cover_image_path === '') {
            return;
        }

        Storage::disk($newsletter->cover_image_disk ?? self::COVER_DISK)->delete($newsletter->cover_image_path);
    }
}
