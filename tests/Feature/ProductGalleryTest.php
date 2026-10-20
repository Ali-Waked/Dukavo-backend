<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleScope;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\RoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The product image gallery.
 *
 * A gallery has two invariants worth defending: exactly one primary image (when
 * it is non-empty), and files that always match their rows. Each action is also
 * reachable only through the product that owns the image, so a valid id cannot
 * be edited or deleted through the wrong URL, and never across a tenant.
 */
class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
    }

    public function test_images_are_uploaded_and_the_first_becomes_primary(): void
    {
        $product = $this->product('tee');

        $response = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', [
                'images' => [$this->png('one.png'), $this->png('two.png')],
                'alt_text' => ['Front', 'Back'],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertTrue($response->json('data.0.is_primary'));
        $this->assertFalse($response->json('data.1.is_primary'));
        $this->assertSame('Front', $response->json('data.0.alt_text'));

        Storage::disk('public')->assertExists($response->json('data.0.path'));
        Storage::disk('public')->assertExists($response->json('data.1.path'));

        $this->assertSame($product->getKey(), $response->json('data.0.product_id'));
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->product('tee');

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', [
                'images' => [UploadedFile::fake()->create('notes.txt', 10)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images.0');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $this->product('tee');

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', [
                'images' => [UploadedFile::fake()->create('huge.png', 5000, 'image/png')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images.0');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_promoting_an_image_demotes_the_previous_primary(): void
    {
        $this->product('tee');

        $upload = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', [
                'images' => [$this->png('one.png'), $this->png('two.png')],
            ])
            ->assertCreated();

        $second = $upload->json('data.1.id');

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson('/api/v1/tenant/products/tee/images/'.$second, ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $images = $this->gallery('tee');
        $primary = collect($images)->where('is_primary', true);

        $this->assertCount(1, $primary);
        $this->assertSame($second, $primary->first()['id']);
    }

    public function test_alt_text_can_be_edited(): void
    {
        $this->product('tee');

        $upload = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', ['images' => [$this->png('one.png')]])
            ->assertCreated();

        $imageId = $upload->json('data.0.id');

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson('/api/v1/tenant/products/tee/images/'.$imageId, ['alt_text' => 'A red tee'])
            ->assertOk()
            ->assertJsonPath('data.alt_text', 'A red tee');
    }

    public function test_deleting_the_primary_promotes_the_next_and_removes_the_file(): void
    {
        $this->product('tee');

        $upload = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', [
                'images' => [$this->png('one.png'), $this->png('two.png')],
            ])
            ->assertCreated();

        $firstId = $upload->json('data.0.id');
        $firstPath = $upload->json('data.0.path');
        $secondId = $upload->json('data.1.id');

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson('/api/v1/tenant/products/tee/images/'.$firstId)
            ->assertOk();

        Storage::disk('public')->assertMissing($firstPath);

        $images = $this->gallery('tee');
        $this->assertCount(1, $images);
        $this->assertSame($secondId, $images[0]['id']);
        $this->assertTrue($images[0]['is_primary']);
    }

    public function test_reordering_rewrites_the_gallery_order(): void
    {
        $this->product('tee');

        $upload = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', [
                'images' => [$this->png('a.png'), $this->png('b.png'), $this->png('c.png')],
            ])
            ->assertCreated();

        $ids = collect($upload->json('data'))->pluck('id')->all();
        $desired = [$ids[2], $ids[0], $ids[1]];

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/products/tee/images/reorder', ['images' => $desired])
            ->assertOk();

        $this->assertSame($desired, collect($this->gallery('tee'))->pluck('id')->all());
    }

    public function test_an_image_of_another_product_is_not_reachable(): void
    {
        $this->product('tee');
        $this->product('mug');

        $upload = $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', ['images' => [$this->png('one.png')]])
            ->assertCreated();

        $imageId = $upload->json('data.0.id');

        // The image id is real, but it belongs to `tee`, not `mug`.
        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson('/api/v1/tenant/products/mug/images/'.$imageId)
            ->assertNotFound();

        $this->assertCount(1, $this->gallery('tee'));
    }

    public function test_uploading_to_another_tenants_product_is_not_found(): void
    {
        $theirs = $this->tenantWithStore();
        $theirStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $theirs->getKey())
            ->firstOrFail();

        Product::factory()->create([
            'tenant_id' => $theirs->getKey(),
            'store_id' => $theirStore->getKey(),
            'slug' => 'their-product',
        ]);

        $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/their-product/images', ['images' => [$this->png('one.png')]])
            ->assertNotFound();
    }

    public function test_the_gallery_is_refused_without_update_permission(): void
    {
        $this->product('tee');

        $viewerRole = app(RoleService::class)->createRole(
            'Catalogue Viewer',
            RoleScope::Tenant,
            'Read-only access to the catalogue.',
            ['products.view'],
            $this->tenant,
        );

        $this->actingAs($this->memberWithRole($this->tenant, $viewerRole))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->post('/api/v1/tenant/products/tee/images', ['images' => [$this->png('one.png')]])
            ->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * The gallery as the show endpoint presents it, so ordering and the primary
     * flag are asserted against the wire shape rather than the database rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gallery(string $slug): array
    {
        return $this->actingAs($this->memberOf($this->tenant))
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/products/'.$slug)
            ->assertOk()
            ->json('data.images');
    }

    private function product(string $slug): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'slug' => $slug,
        ]);
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        ));
    }

    private function memberWithRole(Tenant $tenant, Role $role): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->getKey(), ['is_owner' => false]);
        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);

        return $user->fresh();
    }
}
