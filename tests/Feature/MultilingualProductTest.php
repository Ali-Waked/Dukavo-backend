<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TranslationStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\TranslationRevision;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Multilingual product content end to end.
 *
 * A product stays one record with one price, SKU and stock position. Only its
 * prose is translated, and approved copy lives in the shared review ledger.
 * These tests pin the promises that makes to a merchant and a shopper:
 *
 *  - canonical copy and per-language copy are saved independently,
 *  - a generated proposal is never public until it is approved,
 *  - a shopper always gets approved copy through a deterministic fallback,
 *  - localized slugs, search and SEO stay inside one tenant and store,
 *  - editing a source never deletes an already published translation.
 */
class MultilingualProductTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->store->forceFill([
            'storefront_enabled' => true,
            'enabled_locales' => ['en', 'ar', 'fr'],
            'default_locale' => 'en',
        ])->save();

        $this->merchant = $this->memberOf($this->tenant);
    }

    private function api(User $user): static
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.$path;
    }

    /**
     * A sellable product with one stocked default variant.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $variantAttributes
     * @return array{product: Product, variant: ProductVariant}
     */
    private function sellableProduct(array $attributes = [], array $variantAttributes = []): array
    {
        $name = $attributes['name'] ?? 'Espresso Beans '.Str::lower(Str::random(4));

        $product = $this->withinTenant($this->tenant, fn (): Product => Product::factory()
            ->atStore($this->store)
            ->create($attributes + [
                'tenant_id' => $this->tenant->getKey(),
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
                'price' => 25.00,
                'cost' => 9.00,
            ]));

        $variant = $this->withinTenant($this->tenant, fn (): ProductVariant => $product->variants()->create($variantAttributes + [
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Default',
            'sku' => 'SKU-'.$product->getKey(),
            'price' => 25.00,
            'is_default' => true,
        ]));

        $this->withinTenant($this->tenant, fn () => $variant->inventories()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 20,
            'low_stock_threshold' => 5,
        ]));

        return ['product' => $product, 'variant' => $variant->refresh()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function image(Product $product, array $attributes = []): ProductImage
    {
        return $this->withinTenant($this->tenant, fn (): ProductImage => $product->images()->create($attributes + [
            'tenant_id' => $this->tenant->getKey(),
            'disk' => 'public',
            'path' => 'products/test-'.Str::lower(Str::random(6)).'.jpg',
            'alt_text' => 'A bag of espresso beans',
            'caption' => 'Espresso beans in a bag',
            'is_primary' => true,
            'position' => 0,
        ]));
    }

    /**
     * Save merchant-authored copy through the inline language-tab endpoint.
     *
     * @param  array<string, array<string, string|null>>  $translations
     * @return array<string, mixed>
     */
    private function saveTranslations(string $entity, int $id, array $translations): array
    {
        return $this->api($this->merchant)
            ->putJson("/api/v1/tenant/translations/{$entity}/{$id}", ['translations' => $translations])
            ->assertOk()
            ->json('data');
    }

    public function test_product_creation_persists_canonical_copy_and_approved_language_tabs(): void
    {
        $response = $this->api($this->merchant)
            ->postJson('/api/v1/tenant/products?locale=ar', [
                'name' => 'Espresso Beans',
                'price' => 25,
                'description' => '<p>Roasted daily.</p>',
                'short_description' => 'Small-batch beans.',
                'highlights' => ['Roasted daily', 'Single origin'],
                'features' => ['250g bag'],
                'specifications' => [['label' => 'Weight', 'value' => '250 g']],
                'tags' => ['coffee', 'beans'],
                'seo_title' => 'Espresso Beans',
                'seo_description' => 'Fresh roasted beans.',
                'seo_keywords' => 'coffee, beans',
                'variants' => [[
                    'name' => '250g',
                    'description' => 'A 250 gram bag.',
                    'price' => 25,
                    'is_default' => true,
                    'initial_quantity' => 5,
                ]],
                'translations' => ['ar' => [
                    'name' => 'حبوب إسبريسو',
                    'short_description' => 'حبوب محمصة يوميا.',
                    'highlights' => "محمصة يوميا\nأصل واحد",
                    'specifications' => 'الوزن: 250 غ',
                    'tags' => 'قهوة, حبوب',
                    'seo_title' => 'حبوب إسبريسو',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Espresso Beans')
            ->assertJsonPath('data.short_description', 'Small-batch beans.')
            ->assertJsonPath('data.highlights.0', 'Roasted daily')
            ->assertJsonPath('data.specifications.0.label', 'Weight')
            ->assertJsonPath('data.localized.name', 'حبوب إسبريسو')
            ->assertJsonPath('data.localized.short_description', 'حبوب محمصة يوميا.')
            ->assertJsonPath('data.localized.highlights.0', 'محمصة يوميا')
            ->assertJsonPath('data.localized.specifications.0.label', 'الوزن')
            ->assertJsonPath('data.localized.specifications.0.value', '250 غ')
            ->assertJsonPath('data.localized.tags.0', 'قهوة')
            ->assertJsonPath('data.localized.seo_title', 'حبوب إسبريسو');

        $productId = (int) $response->json('data.id');

        $row = Translation::query()
            ->where('translatable_type', 'product')
            ->where('translatable_id', $productId)
            ->where('locale', 'ar')
            ->where('field', 'name')
            ->firstOrFail();

        $this->assertSame(TranslationStatus::Approved, $row->status);
        $this->assertSame('حبوب إسبريسو', $row->published_text);
        $this->assertTrue($row->manually_edited);
        $this->assertSame($this->merchant->getKey(), $row->reviewed_by);
        $this->assertSame($this->tenant->getKey(), $row->tenant_id);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Espresso Beans',
            'short_description' => 'Small-batch beans.',
            'seo_title' => 'Espresso Beans',
        ]);
    }

    public function test_merchant_reads_localized_copy_and_editing_the_source_never_overwrites_it(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
            'short_description' => 'Small-batch beans.',
        ]);

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
            'short_description' => 'حبوب محمصة يوميا.',
        ]]);

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/products/espresso-beans?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.name', 'Espresso Beans')
            ->assertJsonPath('data.localized.name', 'حبوب إسبريسو')
            ->assertJsonPath('data.localized.short_description', 'حبوب محمصة يوميا.');

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/products/espresso-beans', [
                'short_description' => 'Roasted every morning.',
            ])
            ->assertOk()
            ->assertJsonPath('data.short_description', 'Roasted every morning.');

        $row = Translation::query()
            ->where('translatable_type', 'product')
            ->where('translatable_id', $product->getKey())
            ->where('locale', 'ar')
            ->where('field', 'short_description')
            ->firstOrFail();

        $this->assertSame('حبوب محمصة يوميا.', $row->published_text);

        $this->getJson($this->url('/products/espresso-beans?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.short_description', 'حبوب محمصة يوميا.');
    }

    public function test_a_source_change_marks_copy_stale_but_keeps_it_public(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
        ]);

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
        ]]);

        $row = Translation::query()
            ->where('translatable_type', 'product')
            ->where('translatable_id', $product->getKey())
            ->where('locale', 'ar')
            ->where('field', 'name')
            ->firstOrFail();

        $updatedSlug = $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/products/espresso-beans', [
                'name' => 'Espresso Beans Reserve',
            ])
            ->assertOk()
            ->json('data.slug');

        $row->refresh();

        $this->assertSame(TranslationStatus::Stale, $row->status);
        $this->assertSame('حبوب إسبريسو', $row->published_text);

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$product->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.translations.ar.name.status', 'stale')
            ->assertJsonPath('data.translations.ar.name.published_text', 'حبوب إسبريسو');

        // Stale copy stays visible: hiding a previously approved translation
        // would blank the storefront until a merchant had time to review it.
        $this->getJson($this->url('/products/'.$updatedSlug.'?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.name', 'حبوب إسبريسو');
    }

    public function test_storefront_fallback_uses_requested_then_store_default_then_any_approved_copy(): void
    {
        $this->store->forceFill(['default_locale' => 'ar'])->save();

        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
            'seo_title' => 'Espresso Beans',
        ]);

        $this->saveTranslations('product', $product->getKey(), [
            'ar' => ['name' => 'حبوب إسبريسو'],
            'fr' => ['seo_title' => 'Grains d’espresso'],
        ]);

        // `de` is a supported platform language the store has not enabled, so
        // there is no requested-locale copy. The store's own default wins over
        // another approved language.
        $this->getJson($this->url('/products/espresso-beans?locale=de'))
            ->assertOk()
            ->assertJsonPath('data.name', 'حبوب إسبريسو');

        // No Arabic SEO copy exists, but French does. The deterministic locale
        // order means the approved French value is used rather than blank SEO.
        $this->getJson($this->url('/products/espresso-beans?locale=de'))
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Grains d’espresso');

        // A product with no approved copy at all still serves its canonical
        // value, never an empty field.
        ['product' => $untouched] = $this->sellableProduct([
            'name' => 'Plain Beans',
            'slug' => 'plain-beans',
            'seo_title' => 'Plain Beans',
        ]);

        $this->getJson($this->url('/products/plain-beans?locale=de'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Plain Beans')
            ->assertJsonPath('data.seo.title', 'Plain Beans');
    }

    public function test_localized_slugs_are_unique_and_canonical_urls_keep_working(): void
    {
        ['product' => $first] = $this->sellableProduct([
            'name' => 'Coffee Beans',
            'slug' => 'coffee-beans',
        ]);
        ['product' => $second] = $this->sellableProduct([
            'name' => 'Coffee Grinder',
            'slug' => 'coffee-grinder',
        ]);

        $this->saveTranslations('product', $first->getKey(), ['fr' => [
            'name' => 'Grains de café',
            'slug' => 'grains-de-cafe',
        ]]);

        $this->assertSame('grains-de-cafe', $first->refresh()->localized_slugs['fr']);

        $this->getJson($this->url('/products/grains-de-cafe?locale=fr'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'grains-de-cafe')
            ->assertJsonPath('data.canonical_slug', 'coffee-beans');

        // A localized slug is tenant-wide unique, even against another
        // product's canonical slug.
        $this->api($this->merchant)
            ->putJson("/api/v1/tenant/translations/product/{$second->getKey()}", [
                'translations' => ['fr' => ['slug' => 'grains-de-cafe']],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'product_slug_taken');

        // Changing the localized URL is safe: the canonical URL still resolves
        // in every language.
        $this->saveTranslations('product', $first->getKey(), ['fr' => [
            'slug' => 'grains-frais',
        ]]);

        $this->getJson($this->url('/products/coffee-beans?locale=fr'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'grains-frais');

        $this->getJson($this->url('/products/grains-frais?locale=fr'))
            ->assertOk()
            ->assertJsonPath('data.canonical_slug', 'coffee-beans');
    }

    public function test_storefront_search_matches_localized_copy_and_identifiers_without_duplicates(): void
    {
        ['product' => $product] = $this->sellableProduct(
            ['name' => 'Espresso Beans', 'slug' => 'espresso-beans'],
            ['name' => '250g bag', 'sku' => 'ESPRESSO-001', 'barcode' => '1234567890123'],
        );

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
        ]]);

        // Another tenant's product with the same word must never appear.
        $otherTenant = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->getKey())
            ->firstOrFail();

        $this->withinTenant($otherTenant, fn (): Product => Product::factory()
            ->atStore($otherStore)
            ->create([
                'tenant_id' => $otherTenant->getKey(),
                'name' => 'Espresso Ghost',
                'slug' => 'espresso-ghost',
                'price' => 10,
            ]));

        // The name and the variant SKU both match "espresso" and the product is
        // still returned once, not once per matching row.
        $this->getJson($this->url('/products?search=espresso&locale=en'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'espresso-beans');

        $this->getJson($this->url('/products?search=ESPRESSO-001&locale=en'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'espresso-beans');

        $this->getJson($this->url('/products?search=1234567890123&locale=en'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'espresso-beans');

        $this->getJson($this->url('/products?search='.urlencode('إسبريسو').'&locale=ar'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'حبوب إسبريسو');
    }

    public function test_disabling_a_language_keeps_its_translations_and_reenabling_restores_them(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
        ]);

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
        ]]);

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'enabled_locales' => ['en', 'fr'],
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled_locales', ['en', 'fr']);

        $this->assertDatabaseHas('translations', [
            'translatable_type' => 'product',
            'translatable_id' => $product->getKey(),
            'locale' => 'ar',
            'status' => 'approved',
            'published_text' => 'حبوب إسبريسو',
        ]);

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'enabled_locales' => ['en', 'ar', 'fr'],
            ])
            ->assertOk();

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$product->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.enabled_locales', ['en', 'ar', 'fr'])
            ->assertJsonPath('data.translations.ar.name.status', 'approved')
            ->assertJsonPath('data.translations.ar.name.published_text', 'حبوب إسبريسو');
    }

    public function test_a_newly_enabled_language_is_flagged_untranslated_and_publishes_nothing(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
        ]);

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'enabled_locales' => ['en', 'ar', 'fr', 'es'],
            ])
            ->assertOk();

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$product->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.translations.es.name.status', 'not_translated')
            ->assertJsonPath('data.translations.es.name.published_text', null);

        $this->assertDatabaseMissing('translations', [
            'translatable_type' => 'product',
            'translatable_id' => $product->getKey(),
            'locale' => 'es',
        ]);

        // Enabling a language never publishes the canonical copy as if it were
        // that language's own translation.
        $this->getJson($this->url('/products/espresso-beans?locale=es'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Espresso Beans')
            ->assertJsonPath('data.locale', 'es');
    }

    public function test_manual_bulk_save_approves_copy_and_records_append_only_revisions(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
        ]);

        $saved = $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
            'seo_title' => 'حبوب إسبريسو',
        ]]);

        $this->assertCount(2, $saved['translations']);
        $this->assertSame('approved', $saved['translations'][0]['status']);
        $this->assertSame('manual', $saved['translations'][0]['origin']);
        $this->assertTrue($saved['translations'][0]['manually_edited']);

        $revision = TranslationRevision::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('locale', 'ar')
            ->where('field', 'name')
            ->firstOrFail();

        $this->assertNull($revision->old_text);
        $this->assertSame('حبوب إسبريسو', $revision->new_text);
        $this->assertNull($revision->old_status);
        $this->assertSame(TranslationStatus::Approved, $revision->new_status);
        $this->assertSame($this->merchant->getKey(), $revision->changed_by);

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو محمصة',
        ]]);

        $latest = TranslationRevision::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('locale', 'ar')
            ->where('field', 'name')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('حبوب إسبريسو', $latest->old_text);
        $this->assertSame('حبوب إسبريسو محمصة', $latest->new_text);

        // An empty field is an omission, never an instruction to erase approved
        // copy the storefront is already using.
        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => '',
        ]]);

        $row = Translation::query()
            ->where('translatable_type', 'product')
            ->where('translatable_id', $product->getKey())
            ->where('locale', 'ar')
            ->where('field', 'name')
            ->firstOrFail();

        $this->assertSame('حبوب إسبريسو محمصة', $row->published_text);
    }

    public function test_variant_and_image_copy_are_translated_through_the_shared_ledger(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->sellableProduct(
            ['name' => 'Espresso Beans', 'slug' => 'espresso-beans'],
            ['name' => '250g bag', 'sku' => 'ESP-250', 'barcode' => '9988776655'],
        );

        $image = $this->image($product);

        $this->saveTranslations('product_variant', $variant->getKey(), ['ar' => [
            'name' => 'كيس 250 غ',
            'description' => 'كيس بوزن 250 غراما.',
        ]]);

        $this->saveTranslations('product_image', $image->getKey(), ['ar' => [
            'alt_text' => 'كيس من حبوب الإسبريسو',
            'caption' => 'حبوب الإسبريسو في كيس',
        ]]);

        $this->getJson($this->url('/products/espresso-beans?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.variants.0.name', 'كيس 250 غ')
            ->assertJsonPath('data.variants.0.description', 'كيس بوزن 250 غراما.')
            ->assertJsonPath('data.variants.0.sku', 'ESP-250')
            ->assertJsonPath('data.images.0.alt_text', 'كيس من حبوب الإسبريسو')
            ->assertJsonPath('data.images.0.caption', 'حبوب الإسبريسو في كيس');

        // Operational identity is untouched by translation.
        $this->assertSame('ESP-250', $variant->refresh()->sku);
        $this->assertSame('9988776655', $variant->barcode);
        $this->assertSame('A bag of espresso beans', $image->refresh()->alt_text);
    }

    public function test_localized_storefront_seo_images_and_direction_are_served_for_the_requested_language(): void
    {
        $this->store->forceFill([
            'enabled_locales' => ['en', 'ar'],
            'default_locale' => 'ar',
        ])->save();

        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
            'seo_title' => 'Espresso Beans',
            'seo_description' => 'Fresh roasted beans.',
        ]);

        $image = $this->image($product);

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
            'seo_title' => 'حبوب إسبريسو طازجة',
            'seo_description' => 'حبوب محمصة طازجة كل يوم.',
        ]]);

        $this->saveTranslations('product_image', $image->getKey(), ['ar' => [
            'alt_text' => 'كيس من حبوب الإسبريسو',
            'caption' => 'حبوب إسبريسو محمصة',
        ]]);

        $this->getJson($this->url('/products/espresso-beans?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.direction', 'rtl')
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.available_locales', ['en', 'ar'])
            ->assertJsonPath('data.name', 'حبوب إسبريسو')
            ->assertJsonPath('data.seo.title', 'حبوب إسبريسو طازجة')
            ->assertJsonPath('data.seo.description', 'حبوب محمصة طازجة كل يوم.')
            ->assertJsonPath('data.images.0.alt_text', 'كيس من حبوب الإسبريسو')
            ->assertJsonPath('data.images.0.caption', 'حبوب إسبريسو محمصة')
            ->assertJsonPath('data.canonical_slug', 'espresso-beans');

        $this->getJson($this->url('/?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.direction', 'rtl')
            ->assertJsonPath('data.enabled_locales', ['en', 'ar']);
    }

    public function test_unapproved_copy_is_private_and_other_tenants_cannot_reach_it(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
        ]);

        Translation::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'translatable_type' => 'product',
            'translatable_id' => $product->getKey(),
            'locale' => 'ar',
            'field' => 'name',
            'source_locale' => 'en',
            'source_text' => 'Espresso Beans',
            'translated_text' => 'حبوب إسبريسو',
            'published_text' => null,
            'status' => TranslationStatus::Generated,
            'origin' => 'ai',
        ]);

        $this->getJson($this->url('/products/espresso-beans?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Espresso Beans');

        // Another tenant's product URL must never resolve on this storefront,
        // even when the slug is otherwise valid there.
        $otherTenant = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->getKey())
            ->firstOrFail();

        $foreign = $this->withinTenant($otherTenant, fn (): Product => Product::factory()
            ->atStore($otherStore)
            ->create([
                'tenant_id' => $otherTenant->getKey(),
                'name' => 'Foreign Beans',
                'slug' => 'foreign-beans',
                'price' => 10,
            ]));

        $this->getJson($this->url('/products/foreign-beans'))->assertNotFound();

        $foreignRow = Translation::query()->create([
            'tenant_id' => $otherTenant->getKey(),
            'store_id' => $otherStore->getKey(),
            'translatable_type' => 'product',
            'translatable_id' => $foreign->getKey(),
            'locale' => 'ar',
            'field' => 'name',
            'source_locale' => 'en',
            'source_text' => 'Foreign Beans',
            'translated_text' => 'حبوب أجنبية',
            'published_text' => 'حبوب أجنبية',
            'status' => TranslationStatus::Approved,
            'origin' => 'manual',
        ]);

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$foreign->getKey()}")
            ->assertNotFound();

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$foreignRow->getKey()}/approve")
            ->assertNotFound();
    }

    public function test_store_default_language_must_be_one_of_the_enabled_languages(): void
    {
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'enabled_locales' => ['en', 'ar'],
                'default_locale' => 'fr',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_locale');

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'enabled_locales' => ['en', 'ar'],
                'default_locale' => 'ar',
            ])
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'ar');

        $this->getJson($this->url('/?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'ar');
    }

    public function test_translation_review_and_generation_are_permission_bound(): void
    {
        ['product' => $product] = $this->sellableProduct([
            'name' => 'Espresso Beans',
            'slug' => 'espresso-beans',
        ]);

        $this->saveTranslations('product', $product->getKey(), ['ar' => [
            'name' => 'حبوب إسبريسو',
        ]]);

        $row = Translation::query()
            ->where('translatable_type', 'product')
            ->where('translatable_id', $product->getKey())
            ->where('locale', 'ar')
            ->where('field', 'name')
            ->firstOrFail();

        $staff = $this->memberOf($this->tenant, 'staff');

        $this->api($staff)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
            ])
            ->assertForbidden();

        $this->api($staff)
            ->putJson("/api/v1/tenant/translations/product/{$product->getKey()}", [
                'translations' => ['ar' => ['name' => 'محاولة']],
            ])
            ->assertForbidden();

        $this->api($staff)
            ->postJson("/api/v1/tenant/translations/{$row->getKey()}/approve")
            ->assertForbidden();

        $this->assertSame('حبوب إسبريسو', $row->refresh()->published_text);
    }
}
