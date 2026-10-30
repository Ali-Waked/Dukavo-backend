<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NewsletterArticleStatus;
use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use App\Models\NewsletterSubscriber;
use Database\Seeders\BlogSeeder;
use Database\Seeders\NewsletterSeeder;
use Database\Seeders\Support\DemoBlog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demonstration blog and newsletter audience.
 *
 * Demo seeders run against databases that may already contain real rows, so the
 * tests pin both halves of the contract: a repeat run creates nothing new, and
 * content outside the demonstration set is never touched.
 */
class NewsletterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_blog_seeder_is_idempotent_and_keeps_the_archive_in_order(): void
    {
        Storage::fake('public');

        $this->seed(BlogSeeder::class);
        $dates = Newsletter::query()->orderBy('slug')->pluck('published_at')->map(fn ($date) => $date?->toDateTimeString())->all();

        $this->seed(BlogSeeder::class);

        $this->assertDatabaseCount('newsletters', 12);
        $this->assertSame(10, Newsletter::query()->published()->count());
        $this->assertSame(2, Newsletter::query()->where('status', NewsletterArticleStatus::Draft)->count());
        $this->assertSame(
            $dates,
            Newsletter::query()->orderBy('slug')->pluck('published_at')->map(fn ($date) => $date?->toDateTimeString())->all(),
        );
        $this->assertSame(
            count(DemoBlog::posts()),
            Newsletter::query()->distinct()->count('slug'),
        );
    }

    public function test_the_blog_seeder_publishes_a_cover_for_every_article(): void
    {
        Storage::fake('public');

        $this->seed(BlogSeeder::class);

        $this->assertNull(Newsletter::query()->whereNull('cover_image_path')->first());

        foreach (Newsletter::query()->get() as $edition) {
            $this->assertSame('public', $edition->cover_image_disk);
            $this->assertNotNull($edition->cover_image_path);
            Storage::disk('public')->assertExists($edition->cover_image_path);
        }
    }

    public function test_the_blog_seeder_leaves_editorial_content_outside_the_demo_set_alone(): void
    {
        Storage::fake('public');

        $existing = Newsletter::factory()->create([
            'slug' => 'an-editorial-the-team-wrote',
            'title' => 'An editorial the team wrote',
        ]);

        $this->seed(BlogSeeder::class);

        $this->assertDatabaseCount('newsletters', 13);
        $this->assertSame('An editorial the team wrote', $existing->fresh()->title);
    }

    public function test_demo_articles_follow_the_public_visibility_rules(): void
    {
        Storage::fake('public');
        $this->seed(BlogSeeder::class);

        $this->getJson('/api/v1/public/newsletters?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 10);

        $published = Newsletter::query()->published()->latest('published_at')->firstOrFail();
        $draft = Newsletter::query()->where('status', NewsletterArticleStatus::Draft)->firstOrFail();

        $this->getJson('/api/v1/public/newsletters/'.$published->slug)
            ->assertOk()
            ->assertJsonPath('data.slug', $published->slug);

        $this->getJson('/api/v1/public/newsletters/'.$draft->slug)->assertNotFound();
    }

    public function test_the_newsletter_seeder_is_idempotent_and_keeps_consent_history(): void
    {
        $this->seed(NewsletterSeeder::class);

        $consented = NewsletterSubscriber::query()
            ->orderBy('email')
            ->pluck('consented_at')
            ->map->toDateTimeString()
            ->all();

        $this->seed(NewsletterSeeder::class);

        $this->assertDatabaseCount('newsletter_subscribers', 19);
        $this->assertSame(14, NewsletterSubscriber::query()->where('status', NewsletterStatus::Subscribed)->count());
        $this->assertSame(5, NewsletterSubscriber::query()->where('status', NewsletterStatus::Unsubscribed)->count());
        $this->assertSame(19, NewsletterSubscriber::query()->distinct()->count('email'));
        $this->assertSame(
            $consented,
            NewsletterSubscriber::query()->orderBy('email')->pluck('consented_at')->map->toDateTimeString()->all(),
        );

        $this->assertSame(
            0,
            NewsletterSubscriber::query()
                ->where('status', NewsletterStatus::Subscribed)
                ->whereNotNull('unsubscribed_at')
                ->count(),
        );
    }

    public function test_every_demo_subscriber_uses_a_reserved_domain(): void
    {
        $this->seed(NewsletterSeeder::class);

        $reserved = ['example.com', 'example.org', 'example.net'];

        foreach (NewsletterSubscriber::query()->pluck('email') as $email) {
            $this->assertContains(substr(strrchr($email, '@'), 1), $reserved, $email);
        }
    }

    public function test_the_newsletter_seeder_preserves_real_signups(): void
    {
        $existing = NewsletterSubscriber::factory()->create([
            'email' => 'owner@dukavo.test',
            'source' => 'website',
        ]);

        $this->seed(NewsletterSeeder::class);

        $this->assertDatabaseCount('newsletter_subscribers', 20);
        $this->assertSame('owner@dukavo.test', $existing->fresh()->email);
        $this->assertSame(NewsletterStatus::Subscribed, $existing->fresh()->status);
    }
}
