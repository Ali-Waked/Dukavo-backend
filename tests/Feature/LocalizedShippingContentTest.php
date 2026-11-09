<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multilingual shipping copy.
 *
 * A zone is one pricing rule, not one row per language. These tests pin the
 * two promises that makes to a shopper:
 *
 *  - the fee, threshold and coverage never change with the language,
 *  - copy falls back per field (selected -> store default -> any translation
 *    -> English) instead of showing an empty label or raw translation data.
 */
class LocalizedShippingContentTest extends TestCase
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

        $this->store->forceFill(['storefront_enabled' => true])->save();
        $this->merchant = $this->memberOf($this->tenant);
    }

    private function api(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function publicUrl(string $path = ''): string
    {
        return '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.$path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function zone(array $attributes = []): ShippingZone
    {
        return ShippingZone::factory()->forStore($this->store)->create(array_replace([
            'name' => 'Cairo delivery',
            'city' => 'Cairo',
            'areas' => ['Zamalek'],
            'fee' => 15,
            'free_shipping_threshold' => 75,
        ], $attributes));
    }

    public function test_an_arabic_zone_is_returned_in_arabic_without_changing_the_price(): void
    {
        $zone = $this->zone([
            'description' => 'Orders inside Cairo.',
            'method_name' => 'Standard delivery',
            'method_description' => 'Delivered in two working days.',
            'delivery_instructions' => 'Call on arrival.',
            'terms' => 'Delivery is free above the threshold.',
            'translations' => [
                'ar' => [
                    'name' => 'توصيل القاهرة',
                    'description' => 'طلبات داخل القاهرة.',
                    'method_name' => 'التوصيل العادي',
                    'method_description' => 'يتم التسليم خلال يومي عمل.',
                    'delivery_instructions' => 'اتصل عند الوصول.',
                    'terms' => 'التوصيل مجاني فوق الحد المحدد.',
                ],
            ],
        ]);

        $response = $this->getJson($this->publicUrl('/shipping-zones?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'توصيل القاهرة')
            ->assertJsonPath('data.0.description', 'طلبات داخل القاهرة.')
            ->assertJsonPath('data.0.method_name', 'التوصيل العادي')
            ->assertJsonPath('data.0.method_description', 'يتم التسليم خلال يومي عمل.')
            ->assertJsonPath('data.0.delivery_instructions', 'اتصل عند الوصول.')
            ->assertJsonPath('data.0.terms', 'التوصيل مجاني فوق الحد المحدد.')
            ->assertJsonPath('data.0.fee', 15)
            ->assertJsonPath('data.0.free_shipping_threshold', 75)
            ->assertJsonPath('data.0.city', 'Cairo')
            ->assertJsonPath('data.0.areas.0', 'Zamalek')
            ->assertJsonPath('data.0.locale', 'ar');

        $this->assertArrayNotHasKey('translations', $response->json('data.0'));

        // The same record answers in English without a second zone row.
        $this->getJson($this->publicUrl('/shipping-zones?locale=en'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cairo delivery')
            ->assertJsonPath('data.0.method_name', 'Standard delivery')
            ->assertJsonPath('data.0.fee', 15)
            ->assertJsonPath('data.0.locale', 'en');

        $this->withinTenant($this->tenant, function () use ($zone): void {
            $this->assertSame(1, ShippingZone::query()->count());
            $this->assertSame(15.0, (float) $zone->refresh()->fee);
        });
    }

    public function test_an_english_zone_without_arabic_copy_falls_back_per_field(): void
    {
        $this->zone([
            'name' => 'Alexandria delivery',
            'description' => 'Orders inside Alexandria.',
            'method_name' => 'Standard delivery',
            'delivery_instructions' => 'Call on arrival.',
            'terms' => 'Free above 100.',
        ]);

        $this->getJson($this->publicUrl('/shipping-zones?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alexandria delivery')
            ->assertJsonPath('data.0.description', 'Orders inside Alexandria.')
            ->assertJsonPath('data.0.method_name', 'Standard delivery')
            ->assertJsonPath('data.0.delivery_instructions', 'Call on arrival.')
            ->assertJsonPath('data.0.terms', 'Free above 100.')
            ->assertJsonPath('data.0.locale', 'ar');
    }

    public function test_a_partial_arabic_translation_falls_back_only_for_the_missing_field(): void
    {
        $this->zone([
            'name' => 'Giza delivery',
            'description' => 'Orders inside Giza.',
            'method_name' => 'Standard delivery',
            'translations' => [
                'ar' => [
                    'name' => 'توصيل الجيزة',
                    'method_name' => 'التوصيل العادي',
                ],
            ],
        ]);

        $this->getJson($this->publicUrl('/shipping-zones?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'توصيل الجيزة')
            ->assertJsonPath('data.0.method_name', 'التوصيل العادي')
            ->assertJsonPath('data.0.description', 'Orders inside Giza.');
    }

    public function test_the_store_default_language_is_used_before_any_other_translation(): void
    {
        // The tenant's language is Arabic; this store's shoppers get Arabic
        // when their selected language has no copy.
        $this->tenant->forceFill(['locale' => 'ar'])->save();

        $this->zone([
            'name' => 'Mansoura delivery',
            'description' => null,
            'translations' => [
                'ar' => ['description' => 'طلبات داخل المنصورة.'],
            ],
        ]);

        // English has no description of its own, so the store default (Arabic)
        // is preferred over leaving the field empty.
        $this->getJson($this->publicUrl('/shipping-zones?locale=en'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Mansoura delivery')
            ->assertJsonPath('data.0.description', 'طلبات داخل المنصورة.');
    }

    public function test_accept_language_switches_the_shipping_language(): void
    {
        $this->zone([
            'name' => 'Luxor delivery',
            'translations' => [
                'ar' => ['name' => 'توصيل الأقصر'],
            ],
        ]);

        $this->withHeader('Accept-Language', 'ar-EG,ar;q=0.9')
            ->getJson($this->publicUrl('/shipping-zones'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'توصيل الأقصر')
            ->assertJsonPath('data.0.locale', 'ar');

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->getJson($this->publicUrl('/shipping-zones'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Luxor delivery')
            ->assertJsonPath('data.0.locale', 'en');
    }

    public function test_a_missing_method_name_falls_back_to_the_zone_name(): void
    {
        $this->zone([
            'name' => 'Aswan delivery',
            'method_name' => null,
            'translations' => [
                'ar' => ['name' => 'توصيل أسوان'],
            ],
        ]);

        $this->getJson($this->publicUrl('/shipping-zones?locale=ar'))
            ->assertOk()
            ->assertJsonPath('data.0.method_name', 'توصيل أسوان');
    }

    public function test_the_merchant_can_save_incomplete_translations_without_losing_existing_copy(): void
    {
        $created = $this->api($this->merchant)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones', [
                'name' => 'Cairo delivery',
                'description' => 'Orders inside Cairo.',
                'method_name' => 'Standard delivery',
                'translations' => [
                    'ar' => [
                        'name' => 'توصيل القاهرة',
                        'description' => 'طلبات داخل القاهرة.',
                    ],
                ],
                'city' => 'Cairo',
                'fee' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cairo delivery')
            ->assertJsonPath('data.name_ar', 'توصيل القاهرة')
            ->assertJsonPath('data.description_ar', 'طلبات داخل القاهرة.')
            ->assertJsonPath('data.translations.ar.name', 'توصيل القاهرة');

        $zoneId = $created->json('data.id');

        // Edit only the English side. The Arabic copy must survive.
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zoneId, [
                'name' => 'Cairo same-day delivery',
                'method_name' => 'Same-day delivery',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cairo same-day delivery')
            ->assertJsonPath('data.method_name', 'Same-day delivery')
            ->assertJsonPath('data.name_ar', 'توصيل القاهرة')
            ->assertJsonPath('data.description_ar', 'طلبات داخل القاهرة.');

        // Now add the Arabic method name only. Existing Arabic fields stay.
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zoneId, [
                'translations' => [
                    'ar' => ['method_name' => 'التوصيل في نفس اليوم'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'توصيل القاهرة')
            ->assertJsonPath('data.method_name_ar', 'التوصيل في نفس اليوم');

        // Explicitly clearing one Arabic field only clears that field.
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zoneId, [
                'description_ar' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.description_ar', null)
            ->assertJsonPath('data.name_ar', 'توصيل القاهرة')
            ->assertJsonPath('data.method_name_ar', 'التوصيل في نفس اليوم');
    }

    public function test_arabic_aliases_are_accepted_without_duplicating_the_zone(): void
    {
        $created = $this->api($this->merchant)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones', [
                'name' => 'Tanta delivery',
                'name_ar' => 'توصيل طنطا',
                'description' => 'Orders inside Tanta.',
                'description_ar' => 'طلبات داخل طنطا.',
                'delivery_instructions_ar' => 'اتصل قبل التسليم.',
                'fee' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Tanta delivery')
            ->assertJsonPath('data.name_ar', 'توصيل طنطا')
            ->assertJsonPath('data.delivery_instructions_ar', 'اتصل قبل التسليم.')
            ->assertJsonPath('data.fee', 12);

        $zoneId = $created->json('data.id');

        $this->withinTenant($this->tenant, function () use ($zoneId): void {
            $this->assertSame(1, ShippingZone::query()->count());
            $this->assertSame(12.0, (float) ShippingZone::query()->findOrFail($zoneId)->fee);
        });
    }

    public function test_validation_rejects_overlong_arabic_copy_without_touching_the_english_row(): void
    {
        $zone = $this->zone([
            'name' => 'Suez delivery',
            'translations' => ['ar' => ['name' => 'توصيل السويس']],
        ]);

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zone->getKey(), [
                'translations' => ['ar' => ['name' => str_repeat('م', 121)]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations.ar.name');

        $this->assertSame('Suez delivery', $zone->fresh()->name);
        $this->assertSame('توصيل السويس', $zone->fresh()->translationDocument()['ar']['name']);
    }

    public function test_an_unsupported_translation_locale_is_ignored_safely(): void
    {
        $created = $this->api($this->merchant)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones', [
                'name' => 'Sohag delivery',
                'translations' => [
                    'xx' => ['name' => 'Unsupported copy'],
                    'ar' => ['name' => 'توصيل سوهاج'],
                ],
                'fee' => 9,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name_ar', 'توصيل سوهاج')
            ->assertJsonPath('data.fee', 9);

        $this->assertSame(
            ['ar' => ['name' => 'توصيل سوهاج']],
            $created->json('data.translations'),
        );
    }

    public function test_existing_zones_without_translations_keep_working(): void
    {
        $this->zone(['name' => 'Legacy zone', 'fee' => 7]);

        $this->getJson($this->publicUrl('/shipping-zones'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Legacy zone')
            ->assertJsonPath('data.0.method_name', 'Legacy zone')
            ->assertJsonPath('data.0.fee', 7);

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Legacy zone')
            ->assertJsonPath('data.0.translations', []);
    }

    public function test_shipping_translations_never_cross_a_store_or_tenant_boundary(): void
    {
        $this->zone([
            'name' => 'Visible zone',
            'translations' => ['ar' => ['name' => 'المنطقة الظاهرة']],
        ]);

        $sibling = Store::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        ShippingZone::factory()->forStore($sibling)->create([
            'name' => 'Sibling zone',
            'translations' => ['ar' => ['name' => 'منطقة أخرى']],
        ]);

        $this->getJson($this->publicUrl('/shipping-zones?locale=ar'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'المنطقة الظاهرة');

        $otherTenant = $this->tenantWithStore(['slug' => 'foreign-shop-tenant']);
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->getKey())
            ->firstOrFail();
        $otherStore->forceFill(['storefront_enabled' => true])->save();

        $foreignZone = ShippingZone::factory()->forStore($otherStore)->create([
            'name' => 'Foreign zone',
            'translations' => ['ar' => ['name' => 'منطقة أجنبية']],
        ]);

        $this->getJson('/api/v1/public/storefront/'.$otherTenant->slug.'/'.$otherStore->slug.'/shipping-zones?locale=ar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $foreignZone->getKey())
            ->assertJsonPath('data.0.name', 'منطقة أجنبية');

        // A zone from the sibling store cannot be edited through the wrong URL.
        $siblingZone = ShippingZone::query()
            ->withoutGlobalScopes()
            ->where('store_id', $sibling->getKey())
            ->firstOrFail();

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$siblingZone->getKey(), [
                'translations' => ['ar' => ['name' => 'مُختطف']],
            ])
            ->assertNotFound();
    }
}
