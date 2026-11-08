<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ExchangeRateUnavailable;
use App\Models\CurrencyConversion;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Currency\Contracts\ExchangeRateProvider;
use App\Services\Currency\ExchangeRate;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store's base currency is a financial setting, not a label.
 *
 * These tests pin down the guarantees a merchant relies on: current catalogue
 * prices are converted at a real, server-fetched rate; a provider outage leaves
 * every row untouched; a retried request cannot convert twice; historical orders
 * keep their original amounts; and one tenant can never convert another's store.
 */
class StoreCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->user = $this->memberOf($this->tenant);
    }

    public function test_a_new_store_defaults_to_usd_and_exposes_currency_metadata(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey())
            ->assertOk();

        $response->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.currency_name', 'US Dollar')
            ->assertJsonPath('data.currency_symbol', '$')
            ->assertJsonPath('data.currency_decimals', 2);
    }

    public function test_conversion_reprices_current_catalogue_and_leaves_historical_orders_untouched(): void
    {
        $this->bindRate(0.5);

        $product = $this->product(price: 100, cost: 40);

        $order = $this->withinTenant($this->tenant, fn () => Order::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
            'placed_at' => now(),
        ]));

        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', [
                'currency' => 'EUR',
                'idempotency_key' => 'convert-1',
                'confirm' => true,
            ])
            ->assertOk();

        $response->assertJsonPath('data.store.currency', 'EUR')
            ->assertJsonPath('data.conversion.status', 'completed')
            ->assertJsonPath('data.conversion.products_converted', 1);

        $this->assertSame('EUR', $this->store->refresh()->currency);
        $this->assertSame(2, $this->store->currency_decimals);
        $this->assertNotNull($this->store->currency_updated_at);

        $product->refresh();
        $this->assertSame('EUR', $product->currency);
        $this->assertSame(50.0, (float) $product->price);
        $this->assertSame(20.0, (float) $product->cost);

        // History is immutable: the order still reads exactly as it was placed.
        $order->refresh();
        $this->assertSame('USD', $order->currency);
        $this->assertSame(100.0, (float) $order->grand_total);

        $conversion = $this->withinTenant($this->tenant, fn () => CurrencyConversion::query()->firstOrFail());
        $this->assertSame('USD', $conversion->from_currency);
        $this->assertSame('EUR', $conversion->to_currency);
        $this->assertSame(0.5, (float) $conversion->exchange_rate);
        $this->assertSame('test', $conversion->rate_source);
    }

    public function test_conversion_reprices_shipping_fees_and_free_shipping_thresholds(): void
    {
        $this->bindRate(0.5);

        $zone = $this->withinTenant($this->tenant, fn (): ShippingZone => ShippingZone::factory()->forStore($this->store)->create([
            'fee' => 10,
            'free_shipping_threshold' => 100,
        ]));

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency/preview?currency=EUR')
            ->assertOk()
            ->assertJsonPath('data.shipping_zones', 1);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', [
                'currency' => 'EUR',
                'idempotency_key' => 'convert-shipping',
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.conversion.shipping_zones_converted', 1);

        $zone->refresh();
        $this->assertSame(5.0, (float) $zone->fee);
        $this->assertSame(50.0, (float) $zone->free_shipping_threshold);
    }

    public function test_replaying_an_idempotency_key_returns_the_same_receipt_without_converting_again(): void
    {
        $this->bindRate(0.5);
        $product = $this->product(price: 100, cost: 40);

        $payload = [
            'currency' => 'EUR',
            'idempotency_key' => 'convert-once',
            'confirm' => true,
        ];

        $first = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', $payload)
            ->assertOk();

        $second = $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', $payload)
            ->assertOk();

        $this->assertSame(
            $first->json('data.conversion.id'),
            $second->json('data.conversion.id'),
        );

        $this->assertSame(1, $this->withinTenant(
            $this->tenant,
            fn (): int => CurrencyConversion::query()->count(),
        ));

        // Not converted twice: the price is still the first conversion's result.
        $this->assertSame(50.0, (float) $product->refresh()->price);
    }

    public function test_a_provider_outage_refuses_the_change_and_leaves_every_amount_untouched(): void
    {
        $this->app->instance(ExchangeRateProvider::class, new class implements ExchangeRateProvider
        {
            public function fetch(string $from, string $to): ExchangeRate
            {
                throw new ExchangeRateUnavailable('Provider is offline.');
            }
        });

        $product = $this->product(price: 100, cost: 40);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', [
                'currency' => 'EUR',
                'idempotency_key' => 'convert-offline',
                'confirm' => true,
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'exchange_rate_unavailable');

        $this->assertSame('USD', $this->store->refresh()->currency);
        $product->refresh();
        $this->assertSame('USD', $product->currency);
        $this->assertSame(100.0, (float) $product->price);
        $this->assertSame(40.0, (float) $product->cost);
    }

    public function test_preview_reports_the_rate_and_sample_without_changing_anything(): void
    {
        $this->bindRate(0.25);
        $product = $this->product(price: 100, cost: 40);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency/preview?currency=EUR')
            ->assertOk()
            ->assertJsonPath('data.from_currency', 'USD')
            ->assertJsonPath('data.to_currency', 'EUR')
            ->assertJsonPath('data.rate', 0.25)
            ->assertJsonPath('data.products', 1)
            ->assertJsonPath('data.sample.0.price_after', 25);

        $this->assertSame('USD', $this->store->refresh()->currency);
        $this->assertSame(100.0, (float) $product->refresh()->price);
    }

    public function test_an_ordinary_store_update_cannot_change_the_currency(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson('/api/v1/tenant/stores/'.$this->store->getKey(), [
                'currency' => 'EUR',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'currency_requires_conversion');

        $this->assertSame('USD', $this->store->refresh()->currency);
    }

    public function test_unsupported_currencies_are_rejected(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', [
                'currency' => 'XYZ',
                'idempotency_key' => 'convert-unsupported',
                'confirm' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_a_tenant_cannot_convert_another_tenants_store(): void
    {
        $other = $this->tenantWithStore();
        $foreign = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $other->getKey())
            ->firstOrFail();

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$foreign->getKey().'/currency', [
                'currency' => 'EUR',
                'idempotency_key' => 'cross-tenant',
                'confirm' => true,
            ])
            ->assertNotFound();

        $this->assertSame('USD', $foreign->refresh()->currency);
    }

    public function test_converting_the_default_store_synchronises_the_workspace_currency(): void
    {
        $this->bindRate(0.5);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/currency', [
                'currency' => 'EUR',
                'idempotency_key' => 'sync-workspace',
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.store.currency', 'EUR');

        $this->assertSame('EUR', $this->tenant->fresh()->currency);

        // The active tenant payload the SPA boots from must agree as well.
        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.active_tenant.currency', 'EUR');
    }

    public function test_converting_a_secondary_store_does_not_change_the_workspace_currency(): void
    {
        $this->bindRate(0.5);

        $secondary = $this->withinTenant($this->tenant, fn (): Store => Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'currency' => 'USD',
            'is_default' => false,
        ]));

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$secondary->getKey().'/currency', [
                'currency' => 'EUR',
                'idempotency_key' => 'sync-secondary',
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.store.currency', 'EUR');

        $this->assertSame('EUR', $secondary->fresh()->currency);
        $this->assertSame('USD', $this->tenant->fresh()->currency);
        $this->assertSame('USD', $this->store->fresh()->currency);
    }

    public function test_promoting_a_store_synchronises_the_workspace_currency(): void
    {
        $secondary = $this->withinTenant($this->tenant, fn (): Store => Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'currency' => 'AED',
            'is_default' => false,
        ]));

        $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/stores/'.$secondary->getKey().'/default')
            ->assertOk()
            ->assertJsonPath('data.currency', 'AED');

        $this->assertSame('AED', $this->tenant->fresh()->currency);
        $this->assertFalse($this->store->fresh()->is_default);
        $this->assertTrue($secondary->fresh()->is_default);
    }

    private function bindRate(float $rate): void
    {
        $this->app->instance(ExchangeRateProvider::class, new class($rate) implements ExchangeRateProvider
        {
            public function __construct(private readonly float $rate) {}

            public function fetch(string $from, string $to): ExchangeRate
            {
                return new ExchangeRate(
                    from: strtoupper($from),
                    to: strtoupper($to),
                    rate: $this->rate,
                    source: 'test',
                    fetchedAt: CarbonImmutable::now(),
                );
            }
        });
    }

    private function product(float $price, float $cost): Product
    {
        return $this->withinTenant($this->tenant, fn (): Product => Product::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'price' => $price,
            'cost' => $cost,
            'currency' => 'USD',
        ]));
    }
}
