<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public checkout.
 *
 * The amount a shopper pays is decided by the server: catalogue prices, stock
 * reservation and delivery fees all come from tenant-owned records. Every test
 * below either tampers with the payload or races the same request, and asserts
 * that the server still produces one correct order.
 */
class StorefrontCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->store->forceFill(['storefront_enabled' => true])->save();
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.$path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $variantId, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_name' => 'Nour Customer',
            'customer_phone' => '+15550001111',
            'customer_email' => 'nour@example.test',
            'area' => 'Zamalek',
            'payment_method' => 'cod',
            'idempotency_key' => 'checkout-'.uniqid('', true),
            'shipping_address' => [
                'contact_name' => 'Nour Customer',
                'line_one' => '12 Nile Street',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'items' => [
                ['product_variant_id' => $variantId, 'quantity' => 2],
            ],
        ], $overrides);
    }

    private function zone(array $attributes = []): ShippingZone
    {
        return ShippingZone::factory()->forStore($this->store)->create(array_replace([
            'city' => 'Cairo',
            'areas' => null,
            'fee' => 15,
            'free_shipping_threshold' => null,
        ], $attributes));
    }

    public function test_cod_checkout_uses_catalogue_prices_and_server_shipping(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $zone = $this->zone(['fee' => 15]);

        $response = $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2, 'unit_price' => 0.01]],
        ]))->assertCreated();

        $response
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.subtotal', 20)
            ->assertJsonPath('data.shipping_total', 15)
            ->assertJsonPath('data.grand_total', 35)
            ->assertJsonPath('data.items.0.unit_price', 10)
            ->assertJsonPath('data.items.0.line_total', 20);

        $this->assertNotEmpty($response->json('data.tracking_token'));

        $order = $this->withinTenant($this->tenant, fn (): Order => Order::query()
            ->with('items')
            ->where('order_number', $response->json('data.order_number'))
            ->firstOrFail());

        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('storefront', $order->source);
        $this->assertSame($zone->getKey(), $order->shipping_zone_id);
        $this->assertSame($variant->getKey(), $order->items->first()->product_variant_id);

        $inventory = $this->withinTenant($this->tenant, fn (): Inventory => Inventory::query()
            ->where('store_id', $this->store->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail());

        $this->assertSame(2, $inventory->reserved_quantity);
    }

    public function test_online_payment_is_refused_instead_of_pretending_to_charge(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'payment_method' => 'online',
        ]))->assertStatus(409);

        $this->withinTenant($this->tenant, function (): void {
            $this->assertSame(0, Order::query()->count());
        });
    }

    public function test_disabled_cash_on_delivery_is_refused(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);
        $this->store->forceFill(['payment_settings' => ['cod_enabled' => false]])->save();

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey()))
            ->assertStatus(409);
    }

    public function test_free_shipping_threshold_is_measured_against_the_merchandise_total(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 20);
        $this->zone(['fee' => 15, 'free_shipping_threshold' => 50]);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'idempotency_key' => 'below-threshold',
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        ]))->assertCreated()->assertJsonPath('data.shipping_total', 15);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'idempotency_key' => 'at-threshold',
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 5]],
        ]))->assertCreated()->assertJsonPath('data.shipping_total', 0);
    }

    public function test_an_area_restricted_zone_only_matches_its_named_areas(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 20);
        $this->zone(['city' => 'Cairo', 'areas' => ['Zamalek', 'Maadi'], 'fee' => 12]);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'area' => 'Zamalek',
            'idempotency_key' => 'area-inside',
        ]))->assertCreated()->assertJsonPath('data.shipping_total', 12);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'area' => 'Heliopolis',
            'idempotency_key' => 'area-outside',
        ]))->assertStatus(409);
    }

    public function test_an_address_outside_every_zone_is_refused(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);
        $this->zone(['city' => 'Cairo']);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'shipping_address' => ['city' => 'Alexandria'],
        ]))->assertStatus(409);
    }

    public function test_a_replayed_idempotency_key_returns_the_same_order_and_reserves_stock_once(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $this->zone(['fee' => 5]);
        $payload = $this->payload($variant->getKey(), ['idempotency_key' => 'replay-me']);

        $first = $this->postJson($this->url('/checkout'), $payload)->assertCreated();
        $second = $this->postJson($this->url('/checkout'), $payload)->assertCreated();

        $this->assertSame(
            $first->json('data.order_number'),
            $second->json('data.order_number'),
        );
        $this->assertSame(
            $first->json('data.tracking_token'),
            $second->json('data.tracking_token'),
        );

        $this->withinTenant($this->tenant, function (): void {
            $this->assertSame(1, Order::query()->count());
        });

        $inventory = $this->withinTenant($this->tenant, fn (): Inventory => Inventory::query()
            ->where('store_id', $this->store->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail());

        $this->assertSame(2, $inventory->reserved_quantity);
    }

    public function test_an_idempotency_key_already_used_by_another_store_is_rejected(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'idempotency_key' => 'store-one-key',
        ]))->assertCreated();

        $sibling = Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'storefront_enabled' => true,
        ]);
        ['variant' => $siblingVariant] = $this->stockedProduct($this->tenant, $sibling);

        $this->postJson(
            '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$sibling->slug.'/checkout',
            $this->payload($siblingVariant->getKey(), ['idempotency_key' => 'store-one-key']),
        )->assertStatus(409);
    }

    public function test_a_tracking_token_reveals_only_the_customer_safe_order(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);
        $created = $this->postJson($this->url('/checkout'), $this->payload($variant->getKey()))->assertCreated();
        $token = $created->json('data.tracking_token');

        $response = $this->getJson($this->url('/orders/'.$token))
            ->assertOk()
            ->assertJsonPath('data.order_number', $created->json('data.order_number'))
            ->assertJsonMissingPath('data.tracking_token')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.items.0.unit_cost');

        $this->assertNotEmpty($response->json('data.items'));

        $this->getJson($this->url('/orders/not-the-token'))->assertNotFound();
    }

    public function test_a_tracking_token_cannot_be_read_through_another_tenant(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);
        $token = $this->postJson($this->url('/checkout'), $this->payload($variant->getKey()))
            ->assertCreated()
            ->json('data.tracking_token');

        $other = $this->tenantWithStore(['slug' => 'other-shop']);
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $other->getKey())
            ->firstOrFail();
        $otherStore->forceFill(['storefront_enabled' => true])->save();

        $this->getJson('/api/v1/public/storefront/'.$other->slug.'/'.$otherStore->slug.'/orders/'.$token)
            ->assertNotFound();
    }

    public function test_an_item_from_another_store_cannot_be_checked_out(): void
    {
        $sibling = Store::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        ['variant' => $foreignVariant] = $this->stockedProduct($this->tenant, $sibling);

        $this->postJson($this->url('/checkout'), $this->payload($foreignVariant->getKey()))
            ->assertStatus(409);
    }

    public function test_insufficient_stock_is_refused_and_no_order_is_written(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 1);

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey(), [
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 5]],
        ]))->assertStatus(409);

        $this->withinTenant($this->tenant, function (): void {
            $this->assertSame(0, Order::query()->count());
        });
    }

    public function test_a_product_priced_in_another_currency_cannot_be_ordered(): void
    {
        ['variant' => $variant, 'product' => $product] = $this->stockedProduct($this->tenant, $this->store);

        $this->withinTenant($this->tenant, function () use ($product): void {
            $product->forceFill(['currency' => 'EUR'])->save();
        });

        $this->postJson($this->url('/checkout'), $this->payload($variant->getKey()))
            ->assertStatus(409);
    }
}
