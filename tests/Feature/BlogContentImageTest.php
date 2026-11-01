<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Inline article images.
 *
 * The editor embeds whatever URL this endpoint returns, so the tests pin three
 * properties: only a platform operator may upload, the file lands on Laravel's
 * own public disk under the article content directory, and formats that can
 * carry script (SVG) or documents that are not images are refused.
 */
class BlogContentImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    public function test_uploading_requires_authentication(): void
    {
        $this->postJson('/api/v1/platform/newsletters/content-images', [
            'image' => UploadedFile::fake()->create('photo.png', 100, 'image/png'),
        ])->assertUnauthorized();
    }

    public function test_a_tenant_merchant_cannot_upload_article_images(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->postJson('/api/v1/platform/newsletters/content-images', [
                'image' => UploadedFile::fake()->create('photo.png', 100, 'image/png'),
            ])
            ->assertForbidden();
    }

    public function test_an_operator_can_upload_an_image_into_the_blog_content_directory(): void
    {
        $admin = $this->platformUser('super-admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/platform/newsletters/content-images', [
                'image' => UploadedFile::fake()->create('diagram.png', 100, 'image/png'),
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $path = (string) $response->json('data.path');

        $this->assertStringStartsWith('blogs/content/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString($path, (string) $response->json('data.url'));
    }

    public function test_an_svg_is_rejected_because_it_can_carry_script(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/newsletters/content-images', [
                'image' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_an_image_is_required(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/newsletters/content-images', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }
}
