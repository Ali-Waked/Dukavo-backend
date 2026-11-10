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
 * The merchant side of the shopfront configuration.
 *
 * Viewing is a read grant, changing what customers see or are charged for
 * delivery is a manage grant, and both are confined to the store in the URL.
 */
class StorefrontSettingsEndpointTest extends TestCase
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

        $this->merchant = $this->memberOf($this->tenant, 'merchant');
    }

    private function api(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug);
    }

    public function test_a_merchant_can_publish_the_storefront_and_save_presentation_settings(): void
    {
        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/storefront', [
                'storefront_enabled' => true,
                'description' => 'Our neighbourhood shop.',
                'settings' => [
                    'tagline' => 'Open every day',
                    'contact_phone' => '+201000000000',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.storefront_enabled', true)
            ->assertJsonPath('data.description', 'Our neighbourhood shop.')
            ->assertJsonPath('data.settings.tagline', 'Open every day')
            ->assertJsonPath('data.settings.contact_phone', '+201000000000')
            ->assertJsonPath('data.settings.show_out_of_stock', true);

        $this->assertTrue($this->store->refresh()->storefront_enabled);

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/storefront')
            ->assertOk()
            ->assertJsonPath('data.settings.contact_phone', '+201000000000');
    }

    public function test_staff_may_view_but_not_change_storefront_configuration(): void
    {
        $staff = $this->memberOf($this->tenant, 'staff');

        $this->api($staff)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/storefront')
            ->assertOk();

        $this->api($staff)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/storefront', [
                'storefront_enabled' => true,
            ])
            ->assertForbidden();
    }

    public function test_a_store_of_another_tenant_cannot_be_configured(): void
    {
        $other = $this->tenantWithStore(['slug' => 'foreign-shop']);
        $foreignStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $other->getKey())
            ->firstOrFail();

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$foreignStore->getKey().'/storefront', [
                'storefront_enabled' => true,
            ])
            ->assertNotFound();
    }

    public function test_shipping_zones_are_created_listed_and_updated_for_one_store(): void
    {
        $created = $this->api($this->merchant)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones', [
                'name' => 'Cairo delivery',
                'city' => 'Cairo',
                'areas' => ['Zamalek', 'Maadi'],
                'fee' => 15,
                'free_shipping_threshold' => 75,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cairo delivery')
            ->assertJsonPath('data.fee', 15)
            ->assertJsonPath('data.areas.0', 'zamalek');

        $zoneId = $created->json('data.id');

        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zoneId, [
                'fee' => 20,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.fee', 20)
            ->assertJsonPath('data.is_active', false);

        $this->api($this->merchant)
            ->deleteJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zoneId)
            ->assertNoContent();

        $this->withinTenant($this->tenant, function (): void {
            $this->assertSame(0, ShippingZone::query()->count());
        });
    }

    public function test_a_zone_from_a_sibling_store_cannot_be_edited_through_the_wrong_store_url(): void
    {
        $sibling = Store::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        $zone = ShippingZone::factory()->forStore($sibling)->create();

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones/'.$zone->getKey(), [
                'fee' => 99,
            ])
            ->assertNotFound();

        $this->assertSame(10.00, (float) $zone->refresh()->fee);
    }

    public function test_payment_settings_report_cash_on_delivery_and_honestly_mark_online_as_unavailable(): void
    {
        $this->api($this->merchant)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/payments')
            ->assertOk()
            ->assertJsonPath('data.settings.cod_enabled', true)
            ->assertJsonPath('data.settings.bank_transfer_enabled', false)
            ->assertJsonPath('data.methods.0.code', 'cod')
            ->assertJsonPath('data.methods.0.available', true)
            ->assertJsonPath('data.methods.1.code', 'bank_transfer')
            ->assertJsonPath('data.methods.1.available', false)
            ->assertJsonPath('data.methods.2.code', 'online')
            ->assertJsonPath('data.methods.2.available', false)
            ->assertJsonPath('data.methods.2.unavailable_reason', 'Set the store country before offering online payment.');

        $this->api($this->merchant)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey().'/payments', [
                'cod_enabled' => false,
                'cod_instructions' => 'Pay the courier on arrival.',
                'bank_transfer_enabled' => true,
                'bank_transfer_instructions' => 'Transfer to account 123 and send the receipt.',
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.cod_enabled', false)
            ->assertJsonPath('data.settings.cod_instructions', 'Pay the courier on arrival.')
            ->assertJsonPath('data.settings.bank_transfer_enabled', true)
            ->assertJsonPath('data.settings.bank_transfer_instructions', 'Transfer to account 123 and send the receipt.')
            ->assertJsonPath('data.methods.0.available', false)
            ->assertJsonPath('data.methods.1.available', true);
    }

    public function test_a_member_without_shipping_manage_cannot_create_a_zone(): void
    {
        $staff = $this->memberOf($this->tenant, 'staff');

        $this->api($staff)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/shipping-zones', [
                'name' => 'Alexandria',
                'fee' => 10,
            ])
            ->assertForbidden();
    }
}
