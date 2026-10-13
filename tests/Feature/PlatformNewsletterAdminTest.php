<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Newsletter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Platform administration of newsletter editions.
 *
 * Editions are Dukavo's own content, so the access rules match the rest of the
 * marketing administration: a platform operator may author them, a tenant member
 * may not, and an anonymous visitor never reaches the admin surface. These tests
 * also pin the two safety properties that live in the write path — the body is
 * sanitised, and a cover is replaced without orphaning the previous file.
 */
class PlatformNewsletterAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_newsletter_admin_requires_authentication(): void
    {
        $this->getJson('/api/v1/platform/newsletters')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_a_tenant_merchant_cannot_reach_the_newsletter_endpoints(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/newsletters')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_an_administrator_can_create_and_list_editions(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/newsletters', [
                'title' => 'Autumn Product Update',
                'body' => '<p>What is new this season.</p>',
                'status' => 'published',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Autumn Product Update')
            ->assertJsonPath('data.slug', 'autumn-product-update')
            ->assertJsonPath('data.status', 'published');

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/newsletters')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Autumn Product Update', $rows[0]['title']);
        // The list resource deliberately omits the article body.
        $this->assertArrayNotHasKey('body', $rows[0]);
    }

    public function test_the_body_is_sanitised_on_write(): void
    {
        $admin = $this->platformUser('super-admin');

        $body = '<p>Hello <strong>world</strong></p>'
            .'<script>alert(1)</script>'
            .'<p onclick="evil()">Click <a href="javascript:alert(1)">here</a> '
            .'and <a href="https://example.com">there</a> <a href="/docs">relative</a></p>';

        $stored = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/newsletters', [
                'title' => 'Sanitised',
                'body' => $body,
            ])
            ->assertCreated()
            ->json('data.body');

        $this->assertStringContainsString('<strong>world</strong>', $stored);
        $this->assertStringContainsString('https://example.com', $stored);
        $this->assertStringContainsString('/docs', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    public function test_a_non_latin_title_without_a_slug_is_rejected(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/newsletters', [
                'title' => 'مرحبا بالعالم',
                'body' => '<p>محتوى</p>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        $admin = $this->platformUser('super-admin');
        Newsletter::factory()->create(['slug' => 'taken-slug']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/newsletters', [
                'title' => 'Something',
                'slug' => 'taken-slug',
                'body' => '<p>x</p>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_publishing_stamps_then_drafting_clears_and_the_public_archive_follows(): void
    {
        $admin = $this->platformUser('super-admin');
        $edition = Newsletter::factory()->draft()->create(['slug' => 'launch']);

        $this->getJson('/api/v1/public/newsletters/launch')->assertNotFound();

        $stamp = $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletters/'.$edition->getKey(), ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->json('data.published_at');

        $this->assertNotNull($stamp);

        // Editing a live edition must not move the first publication date.
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletters/'.$edition->getKey(), ['title' => 'Live now'])
            ->assertOk()
            ->assertJsonPath('data.published_at', $stamp);

        $this->getJson('/api/v1/public/newsletters/launch')->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletters/'.$edition->getKey(), ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.published_at', null);

        $this->getJson('/api/v1/public/newsletters/launch')->assertNotFound();
    }

    public function test_a_cover_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');

        $admin = $this->platformUser('super-admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/platform/newsletters', [
                'title' => 'With a cover',
                'body' => '<p>Body</p>',
                'cover_image' => UploadedFile::fake()->create('cover.png', 100, 'image/png'),
            ])
            ->assertCreated();

        $path = Newsletter::query()->sole()->cover_image_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString($path, (string) $response->json('data.cover_image_url'));

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletters/'.Newsletter::query()->sole()->getKey(), ['remove_cover' => true])
            ->assertOk()
            ->assertJsonPath('data.cover_image_url', null);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_replacing_a_cover_removes_the_previous_file(): void
    {
        Storage::fake('public');

        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/platform/newsletters', [
                'title' => 'First cover',
                'body' => '<p>Body</p>',
                'cover_image' => UploadedFile::fake()->create('first.png', 100, 'image/png'),
            ])
            ->assertCreated();

        $first = Newsletter::query()->sole()->cover_image_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($admin, 'sanctum')
            ->patch(
                '/api/v1/platform/newsletters/'.Newsletter::query()->sole()->getKey(),
                ['cover_image' => UploadedFile::fake()->create('second.png', 100, 'image/png')],
            )
            ->assertOk();

        $second = Newsletter::query()->sole()->cover_image_path;

        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_deleting_an_edition_removes_its_cover_and_row(): void
    {
        Storage::fake('public');

        $admin = $this->platformUser('super-admin');
        $edition = Newsletter::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/platform/newsletters', [
                'title' => 'Doomed',
                'body' => '<p>Body</p>',
                'cover_image' => UploadedFile::fake()->create('cover.png', 100, 'image/png'),
            ])
            ->assertCreated();

        $path = (string) Newsletter::query()->where('title', 'Doomed')->sole()->cover_image_path;
        $doomedId = Newsletter::query()->where('title', 'Doomed')->sole()->getKey();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/newsletters/'.$doomedId)
            ->assertNoContent();

        $this->assertDatabaseMissing('newsletters', ['id' => $doomedId]);
        Storage::disk('public')->assertMissing($path);

        // The unrelated edition is untouched.
        $this->assertDatabaseHas('newsletters', ['id' => $edition->getKey()]);
    }

    public function test_slug_availability_reports_taken_and_free(): void
    {
        $admin = $this->platformUser('super-admin');
        $edition = Newsletter::factory()->create(['slug' => 'existing']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/newsletters/slug-availability?slug=existing')
            ->assertOk()
            ->assertJsonPath('data.available', false);

        // The edition's own slug is free when the check ignores that edition.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/newsletters/slug-availability?slug=existing&ignore='.$edition->getKey())
            ->assertOk()
            ->assertJsonPath('data.available', true);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/newsletters/slug-availability?slug=fresh')
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }

    public function test_edition_edits_are_audited(): void
    {
        $admin = $this->platformUser('super-admin');
        $edition = Newsletter::factory()->create(['title' => 'Old title']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletters/'.$edition->getKey(), ['title' => 'New title'])
            ->assertOk();

        $audit = AuditLog::query()
            ->where('auditable_type', 'newsletter')
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame('New title', $audit->new_values['title']);
    }
}
