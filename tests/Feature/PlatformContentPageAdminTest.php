<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform administration of the public content pages.
 *
 * The pages are Dukavo's own copy, so the access rules match the rest of the
 * marketing administration: a platform administrator may edit them, a tenant
 * member may not, and an anonymous visitor never sees the endpoints exist.
 * There is deliberately no create or delete — the pages are seeded and fixed.
 */
class PlatformContentPageAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_content_page_admin_requires_authentication(): void
    {
        $this->getJson('/api/v1/platform/content-pages')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_a_tenant_merchant_cannot_reach_the_content_page_endpoints(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/content-pages')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_platform_administrator_can_list_the_pages(): void
    {
        $this->seed(ContentPageSeeder::class);
        $admin = $this->platformUser('super-admin');

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/content-pages')
            ->assertOk()
            ->json('data');

        // One row per page per language, ordered by key and then locale.
        $this->assertSame(
            [
                ['about', 'ar'],
                ['about', 'en'],
                ['privacy', 'ar'],
                ['privacy', 'en'],
                ['terms', 'ar'],
                ['terms', 'en'],
            ],
            array_map(static fn (array $row): array => [$row['key'], $row['locale']], $rows),
        );
    }

    public function test_an_administrator_can_replace_a_page_and_it_appears_publicly(): void
    {
        $admin = $this->platformUser('super-admin');
        $page = ContentPage::factory()->create(['key' => 'terms', 'title' => 'Old']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), [
                'title' => 'New Terms',
                'intro' => 'Updated intro.',
                'sections' => [
                    ['id' => 'scope', 'title' => 'Scope', 'layout' => 'prose', 'body' => 'Fresh body.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Terms')
            ->assertJsonPath('data.sections.0.id', 'scope');

        $this->getJson('/api/v1/public/pages/terms')
            ->assertOk()
            ->assertJsonPath('data.title', 'New Terms')
            ->assertJsonPath('data.sections.0.body', 'Fresh body.');
    }

    public function test_a_section_without_a_layout_defaults_to_prose(): void
    {
        $admin = $this->platformUser('super-admin');
        $page = ContentPage::factory()->create(['key' => 'privacy']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), [
                'sections' => [
                    ['id' => 'one', 'title' => 'One', 'body' => 'Text'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.sections.0.layout', 'prose');
    }

    public function test_section_validation_rejects_bad_ids_layouts_and_duplicates(): void
    {
        $admin = $this->platformUser('super-admin');
        $page = ContentPage::factory()->create(['key' => 'about']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), [
                'sections' => [['id' => 'Not A Slug', 'title' => 'X', 'body' => 'x']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sections.0.id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), [
                'sections' => [['id' => 'one', 'title' => 'X', 'layout' => 'carousel', 'body' => 'x']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sections.0.layout');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), [
                'sections' => [
                    ['id' => 'same', 'title' => 'One', 'body' => 'a'],
                    ['id' => 'same', 'title' => 'Two', 'body' => 'b'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sections.1.id');
    }

    public function test_publishing_stamps_the_first_published_at_and_unpublishing_clears_it(): void
    {
        $admin = $this->platformUser('super-admin');
        $page = ContentPage::factory()->inactive()->create(['key' => 'terms']);

        $this->assertNull($page->published_at);

        $stamp = $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), ['active' => true])
            ->assertOk()
            ->json('data.published_at');

        $this->assertNotNull($stamp);

        // Editing a live page must not move the first publication date.
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), ['title' => 'Still the same page'])
            ->assertOk()
            ->assertJsonPath('data.published_at', $stamp);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.published_at', null);

        $this->getJson('/api/v1/public/pages/terms')->assertNotFound();
    }

    public function test_content_page_edits_are_audited(): void
    {
        $admin = $this->platformUser('super-admin');
        $page = ContentPage::factory()->create(['key' => 'about', 'title' => 'Old title']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/content-pages/'.$page->getKey(), ['title' => 'New title'])
            ->assertOk();

        $audit = AuditLog::query()
            ->where('auditable_type', 'content_page')
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame('New title', $audit->new_values['title']);
    }
}
