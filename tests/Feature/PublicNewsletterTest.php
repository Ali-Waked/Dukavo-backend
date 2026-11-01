<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unauthenticated newsletter archive.
 *
 * Two properties matter most. Published editions must be readable with no
 * session at all, and a draft must be invisible rather than served empty — a
 * half-written edition is worse than a missing one. The archive is ordered
 * newest-first so the public page and the API agree on what "latest" means.
 */
class PublicNewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_published_edition_is_served_by_slug_without_auth(): void
    {
        Newsletter::factory()->create([
            'title' => 'Spring release',
            'slug' => 'spring-release',
            'body' => '<p>Fresh features.</p>',
        ]);

        $this->getJson('/api/v1/public/newsletters/spring-release')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'spring-release')
            ->assertJsonPath('data.title', 'Spring release')
            ->assertJsonPath('data.body', '<p>Fresh features.</p>');
    }

    public function test_an_edition_reports_an_estimated_reading_time(): void
    {
        Newsletter::factory()->create([
            'slug' => 'long-read',
            'body' => '<p>'.str_repeat('word ', 450).'</p>',
        ]);

        $this->getJson('/api/v1/public/newsletters/long-read')
            ->assertOk()
            ->assertJsonPath('data.reading_minutes', 3);
    }

    public function test_a_draft_is_a_404_never_a_blank_document(): void
    {
        Newsletter::factory()->draft()->create(['slug' => 'unfinished']);

        $this->getJson('/api/v1/public/newsletters/unfinished')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_an_unknown_slug_is_a_404(): void
    {
        $this->getJson('/api/v1/public/newsletters/nonsense')->assertNotFound();
    }

    public function test_the_archive_lists_only_published_editions_newest_first(): void
    {
        Newsletter::factory()->draft()->create(['title' => 'Hidden']);
        Newsletter::factory()->create([
            'title' => 'Older',
            'published_at' => now()->subDays(5),
        ]);
        Newsletter::factory()->create([
            'title' => 'Newer',
            'published_at' => now()->subDay(),
        ]);

        $rows = $this->getJson('/api/v1/public/newsletters')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertSame(['Newer', 'Older'], array_column($rows, 'title'));
        $this->assertArrayNotHasKey('body', $rows[0]);
    }

    public function test_a_paginated_list_includes_meta(): void
    {
        Newsletter::factory()->count(12)->create();

        $response = $this->getJson('/api/v1/public/newsletters?per_page=5')
            ->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(12, $response->json('meta.total'));
        $this->assertSame(5, $response->json('meta.per_page'));
    }

    public function test_an_explicit_arabic_locale_serves_the_arabic_article(): void
    {
        Newsletter::factory()->create([
            'slug' => 'bilingual',
            'title' => 'English title',
            'title_ar' => 'عنوان عربي',
            'excerpt' => 'English summary',
            'excerpt_ar' => 'ملخص عربي',
            'body' => '<p>English body.</p>',
            'body_ar' => '<p>نص عربي.</p>',
        ]);

        $this->getJson('/api/v1/public/newsletters/bilingual?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.title', 'عنوان عربي')
            ->assertJsonPath('data.excerpt', 'ملخص عربي')
            ->assertJsonPath('data.body', '<p>نص عربي.</p>')
            ->assertJsonPath('data.locale', 'ar');
    }

    public function test_accept_language_selects_the_arabic_article(): void
    {
        Newsletter::factory()->create([
            'slug' => 'header-locale',
            'title' => 'English title',
            'title_ar' => 'عنوان عربي',
            'body' => '<p>English body.</p>',
            'body_ar' => '<p>نص عربي.</p>',
        ]);

        $this->withHeader('Accept-Language', 'ar-EG,ar;q=0.9,en;q=0.8')
            ->getJson('/api/v1/public/newsletters/header-locale')
            ->assertOk()
            ->assertJsonPath('data.title', 'عنوان عربي')
            ->assertJsonPath('data.locale', 'ar');
    }

    public function test_a_missing_arabic_field_falls_back_to_english(): void
    {
        Newsletter::factory()->create([
            'slug' => 'partial-translation',
            'title' => 'English title',
            'title_ar' => 'عنوان عربي',
            'excerpt' => 'English summary',
            'excerpt_ar' => null,
            'body' => '<p>English body.</p>',
            'body_ar' => '<p>نص عربي.</p>',
        ]);

        $this->getJson('/api/v1/public/newsletters/partial-translation?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.title', 'عنوان عربي')
            ->assertJsonPath('data.excerpt', 'English summary')
            ->assertJsonPath('data.body', '<p>نص عربي.</p>');
    }

    public function test_the_archive_resolves_titles_and_excerpts_in_the_requested_locale(): void
    {
        Newsletter::factory()->create([
            'slug' => 'arabic-archive',
            'title' => 'English title',
            'title_ar' => 'عنوان عربي',
            'excerpt' => 'English summary',
            'excerpt_ar' => 'ملخص عربي',
        ]);

        $row = $this->getJson('/api/v1/public/newsletters?locale=ar')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('عنوان عربي', $row['title']);
        $this->assertSame('ملخص عربي', $row['excerpt']);
        $this->assertSame('ar', $row['locale']);
    }

    public function test_an_unsupported_locale_degrades_to_english(): void
    {
        Newsletter::factory()->create([
            'slug' => 'english-fallback',
            'title' => 'English title',
            'title_ar' => 'عنوان عربي',
        ]);

        $this->getJson('/api/v1/public/newsletters/english-fallback?locale=fr')
            ->assertOk()
            ->assertJsonPath('data.title', 'English title')
            ->assertJsonPath('data.locale', 'en');
    }
}
