<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Currency\CurrencyCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The single currency catalogue every surface reads from.
 *
 * The ISO 4217 list is not just a dropdown: minor units decide how a converted
 * amount is rounded, and the localized name/symbol decide what a merchant sees.
 * These tests protect both the data and the endpoint that publishes it.
 */
class CurrencyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->user = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_the_catalogue_contains_every_pricing_currency_with_official_minor_units(): void
    {
        $catalog = app(CurrencyCatalog::class);

        $this->assertSame(165, count($catalog->all()));
        $this->assertTrue($catalog->supports('USD'));
        $this->assertTrue($catalog->supports('KWD'));
        $this->assertTrue($catalog->supports('JPY'));
        $this->assertTrue($catalog->supports('CLF'));

        // Codes without a minor unit cannot be a store's pricing currency.
        $this->assertFalse($catalog->supports('XAU'));
        $this->assertFalse($catalog->supports('XDR'));
        $this->assertFalse($catalog->supports('XXX'));

        $this->assertSame(0, $catalog->decimals('JPY'));
        $this->assertSame(2, $catalog->decimals('USD'));
        $this->assertSame(3, $catalog->decimals('KWD'));
        $this->assertSame(3, $catalog->decimals('BHD'));
        $this->assertSame(4, $catalog->decimals('CLF'));
    }

    public function test_rounding_uses_the_target_currency_minor_unit(): void
    {
        $catalog = app(CurrencyCatalog::class);

        $this->assertSame(1.23, $catalog->round(1.2349, 'USD'));
        $this->assertSame(1.0, $catalog->round(1.4, 'JPY'));
        $this->assertSame(1.235, $catalog->round(1.2346, 'KWD'));
        $this->assertSame(1.2346, $catalog->round(1.23456, 'CLF'));
    }

    public function test_the_currency_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/currencies')->assertUnauthorized();
    }

    public function test_the_currency_endpoint_publishes_localized_english_metadata(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/currencies')
            ->assertOk()
            ->assertJsonPath('data.default', 'USD')
            ->assertJsonPath('data.locale', 'en');

        $currencies = collect($response->json('data.currencies'))->keyBy('code');

        $this->assertCount(165, $currencies);
        $this->assertSame('US Dollar', $currencies['USD']['name']);
        $this->assertSame('$', $currencies['USD']['symbol']);
        $this->assertSame(2, $currencies['USD']['decimals']);
        $this->assertSame('JPY', $currencies['JPY']['code']);

        // English and Arabic are both present, so a language switch never
        // needs another round trip.
        $this->assertSame('دينار كويتي', $currencies['KWD']['name_ar']);
        $this->assertSame(3, $currencies['KWD']['decimals']);
    }

    public function test_the_currency_endpoint_localizes_names_and_symbols_for_arabic(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/currencies?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar');

        $currencies = collect($response->json('data.currencies'))->keyBy('code');

        $this->assertSame('دولار أمريكي', $currencies['USD']['name']);
        $this->assertSame('US Dollar', $currencies['USD']['name_en']);
        $this->assertSame(2, $currencies['USD']['decimals']);
    }

    public function test_the_currency_endpoint_is_not_tenant_scoped(): void
    {
        // No X-Tenant header: a personal or platform surface must still be able
        // to read the catalogue.
        $this->actingAs($this->user)
            ->getJson('/api/v1/currencies')
            ->assertOk()
            ->assertJsonStructure(['data' => ['default', 'locale', 'currencies']]);
    }
}
