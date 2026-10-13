<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unauthenticated long-form pages: terms, privacy and about.
 *
 * Two properties matter most. They must be readable with no session at all,
 * and an unpublished page must be invisible rather than served empty — a
 * half-written policy is worse than a missing one.
 */
class PublicContentPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_published_page_is_served_by_key_without_auth(): void
    {
        ContentPage::factory()->create([
            'key' => 'terms',
            'title' => 'Terms & Conditions',
            'sections' => [
                ['id' => 'first', 'title' => 'First', 'layout' => 'prose', 'body' => 'Hello.'],
            ],
        ]);

        $this->getJson('/api/v1/public/pages/terms')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.key', 'terms')
            ->assertJsonPath('data.title', 'Terms & Conditions')
            ->assertJsonPath('data.sections.0.id', 'first');
    }

    public function test_an_unpublished_page_is_a_404_never_a_blank_document(): void
    {
        ContentPage::factory()->inactive()->create(['key' => 'privacy']);

        $this->getJson('/api/v1/public/pages/privacy')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_an_unknown_key_is_a_404(): void
    {
        $this->getJson('/api/v1/public/pages/nonsense')->assertNotFound();
    }

    public function test_the_seeded_pages_are_all_published_and_serve_their_sections(): void
    {
        $this->seed(ContentPageSeeder::class);

        foreach (['terms', 'privacy', 'about'] as $key) {
            $response = $this->getJson('/api/v1/public/pages/'.$key)
                ->assertOk()
                ->assertJsonPath('data.key', $key)
                ->assertJsonPath('data.active', true);

            $this->assertNotEmpty($response->json('data.sections'));
        }
    }

    public function test_the_seeded_legal_pages_leave_editable_placeholders_instead_of_inventing_details(): void
    {
        $this->seed(ContentPageSeeder::class);

        $terms = (string) json_encode(
            ContentPage::query()->where('key', 'terms')->where('locale', 'en')->firstOrFail()->sections,
        );

        // The entity name and governing law are not made up: they are explicit
        // placeholders for the business to complete before publication.
        $this->assertStringContainsString('[LEGAL ENTITY NAME]', $terms);
        $this->assertStringContainsString('[GOVERNING LAW', $terms);
    }

    public function test_the_page_is_served_in_the_requested_language(): void
    {
        $this->seed(ContentPageSeeder::class);

        $this->getJson('/api/v1/public/pages/about', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.key', 'about');

        $this->getJson('/api/v1/public/pages/about', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_the_same_section_anchors_exist_in_every_language(): void
    {
        $this->seed(ContentPageSeeder::class);

        $english = ContentPage::query()->where('key', 'terms')->where('locale', 'en')->firstOrFail();
        $arabic = ContentPage::query()->where('key', 'terms')->where('locale', 'ar')->firstOrFail();

        $this->assertSame(
            array_column($english->sections, 'id'),
            array_column($arabic->sections, 'id'),
        );
    }

    public function test_a_missing_translation_falls_back_to_the_default_language(): void
    {
        ContentPage::factory()->create(['key' => 'terms', 'locale' => 'en', 'title' => 'English only']);

        $this->getJson('/api/v1/public/pages/terms', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.title', 'English only');
    }
}
