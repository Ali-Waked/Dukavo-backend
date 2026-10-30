<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NewsletterArticleStatus;
use App\Models\Newsletter;
use Database\Seeders\Support\DemoBlog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The public blog archive, stored as newsletter editions.
 *
 * Dukavo has one long-form publishing model, not two: `newsletters` powers both
 * the public blog and the platform authoring screen. Seeding it here gives the
 * marketing site real articles without introducing a second table or a parallel
 * content workflow.
 *
 * Idempotent by slug. A second run updates the demonstration articles in place,
 * never duplicates them, and leaves every row with a different slug — including
 * anything a platform editor has published — untouched. Publication dates are
 * fixed in {@see DemoBlog} so re-seeding does not shuffle the archive or move a
 * story to the top of the page.
 *
 * Covers are original vector artwork committed beside the seeder and copied to
 * the public disk, mirroring how {@see CatalogSeeder} publishes product images.
 * No external image service is contacted, so the seed works offline and carries
 * no third-party licensing obligation.
 */
class BlogSeeder extends Seeder
{
    /** Matches NewsletterContentService's cover disk. */
    private const string COVER_DISK = 'public';

    /** Directory inside the public disk where seeded covers are published. */
    private const string COVER_DIRECTORY = 'blog-covers';

    public function run(): void
    {
        $this->publishCovers();

        $inserted = 0;
        $updated = 0;

        foreach (DemoBlog::posts() as $post) {
            $status = NewsletterArticleStatus::from($post['status']);
            $coverPath = $this->coverPath($post['slug']);

            $edition = Newsletter::query()->updateOrCreate(
                ['slug' => $post['slug']],
                [
                    'title' => $post['title'],
                    'excerpt' => $post['excerpt'],
                    'body' => $post['body'],
                    'cover_image_path' => $coverPath,
                    'cover_image_disk' => $coverPath === null ? null : self::COVER_DISK,
                    'status' => $status,
                    'published_at' => $status === NewsletterArticleStatus::Published
                        ? $post['published_at']
                        : null,
                    'meta_title' => $post['meta_title'],
                    'meta_description' => $post['meta_description'],
                ],
            );

            $edition->wasRecentlyCreated ? $inserted++ : $updated++;
        }

        $this->command?->info(sprintf(
            'Blog: %d article(s) inserted, %d updated (%d published, %d draft).',
            $inserted,
            $updated,
            Newsletter::query()->published()->count(),
            Newsletter::query()->where('status', NewsletterArticleStatus::Draft)->count(),
        ));
    }

    /**
     * Copy the committed cover artwork onto the public disk and make sure the
     * storage link exists so `coverImageUrl()` resolves in the browser.
     */
    private function publishCovers(): void
    {
        $source = $this->coverDirectory();

        if (! is_dir($source)) {
            return;
        }

        $files = glob($source.'/*.svg');

        if ($files === false || $files === []) {
            return;
        }

        $disk = Storage::disk(self::COVER_DISK);

        foreach ($files as $file) {
            $disk->put(self::COVER_DIRECTORY.'/'.basename($file), File::get($file));
        }

        if (! file_exists(public_path('storage'))) {
            Artisan::call('storage:link');
        }
    }

    /**
     * The stored path for an article's cover, or null when the artwork is not
     * present (the archive still works; the card simply omits the image).
     */
    private function coverPath(string $slug): ?string
    {
        $file = $this->coverDirectory().'/'.$slug.'.svg';

        return is_file($file) ? self::COVER_DIRECTORY.'/'.$slug.'.svg' : null;
    }

    private function coverDirectory(): string
    {
        return database_path('seeders/demo-covers');
    }
}
