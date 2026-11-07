<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Storefront\StorefrontSettings;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bilingual merchant copy for categories and shop settings.
 *
 * The catalogue is one record per category, not one per language: the slug,
 * hierarchy, stock and tenant ownership stay identical while the prose changes.
 * These tests pin the three promises that makes to a merchant:
 *
 *  - both languages are always readable and writable independently,
 *  - a request asks for one language and gets a per-field English fallback
 *    rather than an empty label,
 *  - Arabic copy never crosses a tenant or store boundary.
 */
class LocalizedShopContentTest extends TestCase
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
        $this->merchant = $this->memberOf($this->tenant);
    }

    private function api(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function category(array $attributes = []): Category
    {
        return Category::factory()->create($attributes + [
            'tenant_id' => $this->tenant->getKey(),
        ]);
    }

    public function test_a_category_returns_both_languages_and_the_renderable_value_for_the_requested_locale(): void
    {
        $category = $this->category([
            'name' => 'Beverages',
            'name_ar' => 'المشروبات',
            'description' => 'Soft drinks, juices and water.',
            'description_ar' => 'مشروبات غازية وعصائر ومياه.',
        ]);

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beverages')
            ->assertJsonPath('data.0.name_ar', 'المشروبات')
            ->assertJsonPath('data.0.localized_name', 'المشروبات')
            ->assertJsonPath('data.0.description', 'Soft drinks, juices and water.')
            ->assertJsonPath('data.0.description_ar', 'مشروبات غازية وعصائر ومياه.')
            ->assertJsonPath('data.0.localized_description', 'مشروبات غازية وعصائر ومياه.')
            ->assertJsonPath('data.0.locale', 'ar');

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories/'.$category->getKey().'?locale=en')
            ->assertOk()
            ->assertJsonPath('data.name', 'Beverages')
            ->assertJsonPath('data.name_ar', 'المشروبات')
            ->assertJsonPath('data.localized_name', 'Beverages')
            ->assertJsonPath('data.localized_description', 'Soft drinks, juices and water.')
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_accept_language_is_honoured_and_unknown_languages_fall_back_to_the_default(): void
    {
        $this->category(['name' => 'Bakery', 'name_ar' => 'المخبوزات']);

        $this->api($this->merchant)
            ->withHeader('Accept-Language', 'ar-EG,ar;q=0.9')
            ->getJson('/api/v1/tenant/categories')
            ->assertOk()
            ->assertJsonPath('data.0.localized_name', 'المخبوزات')
            ->assertJsonPath('data.0.locale', 'ar');

        // An unsupported language must not 404 or blank the payload. No
        // supported language is present in either hint, so the default wins.
        $this->api($this->merchant)
            ->withHeader('Accept-Language', 'fr-FR')
            ->getJson('/api/v1/tenant/categories?locale=fr')
            ->assertOk()
            ->assertJsonPath('data.0.localized_name', 'Bakery')
            ->assertJsonPath('data.0.locale', 'en');
    }

    public function test_editing_one_language_never_overwrites_the_other(): void
    {
        $category = $this->category([
            'name' => 'Dairy',
            'name_ar' => 'الألبان',
            'description' => 'Milk and cheese.',
            'description_ar' => 'حليب وأجبان.',
        ]);

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/categories/'.$category->getKey(), [
                'name_ar' => 'منتجات الألبان',
                'description_ar' => 'حليب وأجبان ولبن.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dairy')
            ->assertJsonPath('data.name_ar', 'منتجات الألبان')
            ->assertJsonPath('data.description', 'Milk and cheese.')
            ->assertJsonPath('data.description_ar', 'حليب وأجبان ولبن.');

        // Now edit only the English side; the Arabic copy must survive.
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/categories/'.$category->getKey(), [
                'name' => 'Dairy & Eggs',
                'description' => 'Milk, cheese and eggs.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dairy & Eggs')
            ->assertJsonPath('data.name_ar', 'منتجات الألبان')
            ->assertJsonPath('data.description', 'Milk, cheese and eggs.')
            ->assertJsonPath('data.description_ar', 'حليب وأجبان ولبن.');

        $fresh = $category->fresh();

        $this->assertSame('Dairy & Eggs', $fresh->name);
        $this->assertSame('منتجات الألبان', $fresh->name_ar);
        $this->assertSame('حليب وأجبان ولبن.', $fresh->description_ar);
    }

    public function test_a_category_can_be_created_with_arabic_copy_or_english_only(): void
    {
        $this->api($this->merchant)
            ->postJson('/api/v1/tenant/categories', [
                'name' => 'Snacks',
                'name_ar' => 'الوجبات الخفيفة',
                'description' => 'Chocolate and nuts.',
                'description_ar' => 'شوكولاتة ومكسرات.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Snacks')
            ->assertJsonPath('data.name_ar', 'الوجبات الخفيفة')
            ->assertJsonPath('data.localized_name', 'Snacks');

        // Arabic is optional. An English-only category still renders in Arabic
        // mode, falling back per field instead of showing an empty name.
        $this->api($this->merchant)
            ->postJson('/api/v1/tenant/categories', [
                'name' => 'Frozen Foods',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name_ar', null)
            ->assertJsonPath('data.localized_name', 'Frozen Foods');

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories?locale=ar&search=Frozen')
            ->assertOk()
            ->assertJsonPath('data.0.localized_name', 'Frozen Foods');
    }

    public function test_arabic_validation_rejects_overlong_copy_without_touching_the_english_row(): void
    {
        $category = $this->category(['name' => 'Pantry', 'name_ar' => 'المؤن']);

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/categories/'.$category->getKey(), [
                'name_ar' => str_repeat('م', 121),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name_ar');

        $this->assertSame('المؤن', $category->fresh()->name_ar);
    }

    public function test_search_matches_arabic_and_english_names_descriptions_and_slugs(): void
    {
        $this->category([
            'name' => 'Beverages',
            'name_ar' => 'المشروبات',
            'slug' => 'beverages',
            'description' => 'Soft drinks and juice.',
            'description_ar' => 'مشروبات غازية وعصائر.',
        ]);

        $this->category([
            'name' => 'Household',
            'name_ar' => 'مستلزمات المنزل',
            'slug' => 'household',
            'description' => 'Cleaning products.',
            'description_ar' => 'منتجات تنظيف.',
        ]);

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories?search=المشروبات')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'beverages');

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories?search=cleaning')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'household');

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories?search=house')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'household');
    }

    public function test_the_category_tree_keeps_the_parent_of_a_matching_child(): void
    {
        $parent = $this->category(['name' => 'Beverages', 'name_ar' => 'المشروبات', 'slug' => 'beverages']);
        $child = $this->category([
            'name' => 'Juices',
            'name_ar' => 'العصائر',
            'slug' => 'juices',
            'parent_id' => $parent->getKey(),
        ]);

        // The Arabic name lives on the child, not the parent. A filtered tree
        // must still nest it under its real parent rather than discard it.
        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories/tree?locale=ar&search=العصائر')
            ->assertOk()
            ->assertJsonPath('data.0.id', $parent->getKey())
            ->assertJsonPath('data.0.children.0.id', $child->getKey());
    }

    public function test_a_store_returns_and_updates_localized_copy_independently(): void
    {
        $this->store->forceFill([
            'name' => 'City Centre',
            'name_ar' => 'وسط المدينة',
            'description' => 'Our flagship store.',
            'description_ar' => 'متجرنا الرئيسي.',
        ])->save();

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/stores?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'City Centre')
            ->assertJsonPath('data.0.name_ar', 'وسط المدينة')
            ->assertJsonPath('data.0.localized_name', 'وسط المدينة')
            ->assertJsonPath('data.0.localized_description', 'متجرنا الرئيسي.')
            ->assertJsonPath('data.0.locale', 'ar');

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'name_ar' => 'المركز',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'City Centre')
            ->assertJsonPath('data.name_ar', 'المركز');

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'name' => 'Downtown',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Downtown')
            ->assertJsonPath('data.name_ar', 'المركز');
    }

    public function test_a_store_can_be_created_with_bilingual_copy(): void
    {
        $this->api($this->merchant)
            ->postJson('/api/v1/tenant/stores', [
                'name' => 'Riverside',
                'name_ar' => 'ضفاف النهر',
                'description' => 'A quiet branch.',
                'description_ar' => 'فرع هادئ.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Riverside')
            ->assertJsonPath('data.name_ar', 'ضفاف النهر')
            ->assertJsonPath('data.description_ar', 'فرع هادئ.');

        $created = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('name', 'Riverside')
            ->firstOrFail();

        $this->assertSame('ضفاف النهر', $created->name_ar);
        $this->assertSame('فرع هادئ.', $created->description_ar);
    }

    public function test_storefront_settings_keep_both_languages_and_localize_the_public_copy(): void
    {
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/storefront', [
                'storefront_enabled' => true,
                'name_ar' => 'المتجر الرئيسي',
                'description' => 'A small neighbourhood shop.',
                'description_ar' => 'متجر صغير في الحي.',
                'settings' => [
                    'tagline' => 'Fresh every day',
                    'tagline_ar' => 'طازج كل يوم',
                    'about' => 'Family run since 1990.',
                    'contact_phone' => '+201000000000',
                    'address' => '12 Market Street',
                    'address_ar' => '١٢ شارع السوق',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'المتجر الرئيسي')
            ->assertJsonPath('data.description_ar', 'متجر صغير في الحي.')
            ->assertJsonPath('data.settings.tagline', 'Fresh every day')
            ->assertJsonPath('data.settings.tagline_ar', 'طازج كل يوم')
            ->assertJsonPath('data.localized_settings.tagline', 'Fresh every day')
            // No Arabic about text was sent, so the English text is the
            // fallback rather than an empty section.
            ->assertJsonPath('data.localized_settings.about', 'Family run since 1990.')
            ->assertJsonMissingPath('data.localized_settings.tagline_ar');

        // Editing the Arabic tagline alone must preserve the English one.
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/storefront', [
                'settings' => ['tagline_ar' => 'طازج من السوق كل يوم'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.tagline', 'Fresh every day')
            ->assertJsonPath('data.settings.tagline_ar', 'طازج من السوق كل يوم');

        $this->assertSame(
            'Fresh every day',
            StorefrontSettings::merge($this->store->fresh()->storefront_settings)['tagline'],
        );
    }

    public function test_the_public_storefront_serves_one_language_with_fallbacks_and_hides_the_raw_arabic_keys(): void
    {
        $this->store->forceFill([
            'storefront_enabled' => true,
            'name' => 'City Centre',
            'name_ar' => 'وسط المدينة',
            'description' => 'Our flagship store.',
            'description_ar' => 'متجرنا الرئيسي.',
            'storefront_settings' => [
                'tagline' => 'Fresh every day',
                'tagline_ar' => 'طازج كل يوم',
                'about' => 'Family run since 1990.',
                'about_ar' => '  ',
                'contact_phone' => '+201000000000',
                'address' => '12 Market Street',
                'address_ar' => '١٢ شارع السوق',
            ],
        ])->save();

        $url = '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug;

        $this->getJson($url.'?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.name', 'وسط المدينة')
            ->assertJsonPath('data.description', 'متجرنا الرئيسي.')
            ->assertJsonPath('data.settings.tagline', 'طازج كل يوم')
            // A blank translation is missing, not an intentional empty section.
            ->assertJsonPath('data.settings.about', 'Family run since 1990.')
            ->assertJsonPath('data.settings.contact_phone', '+201000000000')
            ->assertJsonPath('data.settings.address', '١٢ شارع السوق')
            ->assertJsonMissingPath('data.settings.tagline_ar')
            ->assertJsonMissingPath('data.settings.about_ar');

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.name', 'City Centre')
            ->assertJsonPath('data.description', 'Our flagship store.')
            ->assertJsonPath('data.settings.tagline', 'Fresh every day')
            ->assertJsonPath('data.settings.address', '12 Market Street');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.name', 'وسط المدينة');
    }

    public function test_public_categories_are_localized_with_english_fallback_and_no_raw_arabic_keys(): void
    {
        $category = $this->category([
            'name' => 'Beverages',
            'name_ar' => 'المشروبات',
            'description' => 'Soft drinks.',
            'description_ar' => 'مشروبات غازية.',
        ]);

        $this->store->forceFill(['storefront_enabled' => true])->save();
        $url = '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.'/categories';

        $this->getJson($url.'?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $category->slug)
            ->assertJsonPath('data.0.name', 'المشروبات')
            ->assertJsonPath('data.0.description', 'مشروبات غازية.')
            ->assertJsonPath('data.0.locale', 'ar')
            ->assertJsonMissingPath('data.0.name_ar');

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beverages')
            ->assertJsonPath('data.0.description', 'Soft drinks.')
            ->assertJsonPath('data.0.locale', 'en');
    }

    public function test_arabic_copy_never_crosses_a_tenant_boundary(): void
    {
        $mine = $this->category(['name' => 'Mine', 'name_ar' => 'خاصتي']);
        $otherTenant = $this->tenantWithStore(['slug' => 'another-shop']);
        $theirs = Category::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'name' => 'Theirs',
            'name_ar' => 'خاص بهم',
        ]);

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories?locale=ar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->getKey())
            ->assertJsonPath('data.0.localized_name', 'خاصتي');

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/categories/'.$theirs->getKey().'?locale=ar')
            ->assertNotFound();
    }

    public function test_the_demo_seeders_are_idempotent_and_populate_bilingual_categories_and_stores(): void
    {
        Storage::fake('public');

        $this->seed(TenantSeeder::class);
        $this->seed(CatalogSeeder::class);

        $urban = Tenant::query()->where('slug', 'urban-basket')->firstOrFail();
        $category = Category::query()->withoutGlobalScopes()
            ->where('tenant_id', $urban->getKey())
            ->where('slug', 'fresh-produce')
            ->firstOrFail();
        $store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $urban->getKey())
            ->where('slug', 'city-centre')
            ->firstOrFail();

        $this->assertSame('المنتجات الطازجة', $category->name_ar);
        $this->assertNotEmpty($category->description_ar);
        $this->assertSame('وسط المدينة', $store->name_ar);
        $this->assertNotEmpty(StorefrontSettings::merge($store->storefront_settings)['tagline_ar']);

        // A merchant-owned row with a different slug must survive re-seeding.
        $own = $this->withinTenant($urban, fn (): Category => Category::factory()->create([
            'tenant_id' => $urban->getKey(),
            'name' => 'Merchant own category',
            'name_ar' => 'فئة التاجر',
            'slug' => 'merchant-own-category',
        ]));

        $counts = [
            'tenants' => Tenant::query()->count(),
            'stores' => Store::query()->withoutGlobalScopes()->count(),
            'categories' => Category::query()->withoutGlobalScopes()->count(),
            'products' => Product::query()->withoutGlobalScopes()->count(),
        ];
        $categoryDates = Category::query()->withoutGlobalScopes()->orderBy('id')->pluck('created_at')
            ->map(fn ($date) => $date?->toDateTimeString())
            ->all();

        $this->seed(TenantSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->assertSame($counts['tenants'], Tenant::query()->count());
        $this->assertSame($counts['stores'], Store::query()->withoutGlobalScopes()->count());
        $this->assertSame($counts['categories'], Category::query()->withoutGlobalScopes()->count());
        $this->assertSame($counts['products'], Product::query()->withoutGlobalScopes()->count());

        $this->assertSame('Merchant own category', $own->fresh()->name);
        $this->assertSame('فئة التاجر', $own->fresh()->name_ar);
        $this->assertSame(
            $categoryDates,
            Category::query()->withoutGlobalScopes()->orderBy('id')->pluck('created_at')
                ->map(fn ($date) => $date?->toDateTimeString())
                ->all(),
        );
    }
}
