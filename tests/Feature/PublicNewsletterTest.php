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
}
