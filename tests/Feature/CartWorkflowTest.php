<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The conversation order builder.
 *
 * Carts are proposals, not orders: they are repriced from the catalogue, do not
 * hold stock, and only reserve inventory when a customer-confirmed cart is
 * converted. The tests below concentrate on the refusals — stale totals, foreign
 * products, overselling the last unit and duplicate confirmation — because those
 * are the cases where an order builder can quietly lie to a merchant.
 */
class CartWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    private Customer $customer;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->customer = Customer::factory()->for($this->tenant)->create([
            'name' => 'Nour Customer',
            'phone' => '+15550001111',
        ]);
        $this->conversation = $this->conversationFor($this->customer);
    }

    public function test_cart_prices_come_from_the_catalogue_not_the_request(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 20);
        $opened = $this->openCart();

        $response = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
            'unit_price' => 0.01,
            'line_total' => 0.02,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 10)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.subtotal', 20)
            ->assertJsonPath('data.grand_total', 20)
            ->assertJsonPath('data.items.0.available', 20);
    }

    public function test_a_product_assigned_to_another_store_cannot_be_added(): void
    {
        $otherStore = Store::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $otherStore);
        $opened = $this->openCart();

        $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertStatus(409)->assertJsonPath('code', 'product_not_in_store');
    }

    public function test_a_cart_cannot_hold_more_than_the_store_has_available(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 2);
        $opened = $this->openCart();

        $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 3,
        ])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_a_draft_cannot_become_an_order_before_customer_confirmation(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $opened = $this->openCart();

        $added = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
        ])->assertOk();

        $this->api()->postJson($this->cartPath('/confirm'), [
            'version' => $added->json('data.version'),
            'expected_grand_total' => 20,
        ])->assertStatus(409)->assertJsonPath('code', 'cart_not_confirmable');

        $requested = $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $added->json('data.version'),
            'notify' => false,
        ])->assertOk()
            ->assertJsonPath('data.status', CartStatus::PendingConfirmation->value);

        $confirmed = $this->api()->postJson($this->cartPath('/confirm'), [
            'version' => $requested->json('data.version'),
            'expected_grand_total' => 20,
        ])->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.conversation_id', $this->conversation->getKey())
            ->assertJsonPath('data.grand_total', 20);

        $order = Order::query()->withoutGlobalScopes()
            ->where('order_number', $confirmed->json('data.order_number'))
            ->firstOrFail();
        $cart = $this->freshCart();
        $inventory = $this->inventoryFor($variant);

        $this->assertSame($this->conversation->getKey(), $order->conversation_id);
        $this->assertSame(CartStatus::Converted, $cart->status);
        $this->assertSame($order->getKey(), $cart->order_id);
        $this->assertSame(2, $inventory->reserved_quantity);
        $this->assertSame(10, $inventory->quantity);
    }

    public function test_confirmation_is_refused_when_the_catalogue_total_no_longer_matches(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $opened = $this->openCart();

        $added = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertOk();

        $requested = $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $added->json('data.version'),
            'notify' => false,
        ])->assertOk();

        $this->withinTenant($this->tenant, fn () => $variant->forceFill(['price' => 12])->save());

        $this->api()->postJson($this->cartPath('/confirm'), [
            'version' => $requested->json('data.version'),
            'expected_grand_total' => 10,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'cart_total_changed')
            ->assertJsonPath('message', 'The cart total changed to 12.00. Review the updated proposal before confirming.');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(12.00, (float) $this->freshCart()->grand_total);
    }

    public function test_confirming_twice_returns_the_same_order(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $opened = $this->openCart();

        $added = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertOk();

        $requested = $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $added->json('data.version'),
            'notify' => false,
        ])->assertOk();

        $first = $this->api()->postJson($this->cartPath('/confirm'), [
            'version' => $requested->json('data.version'),
            'expected_grand_total' => 10,
        ])->assertCreated();

        $second = $this->api()->postJson($this->cartPath('/confirm'), [
            'version' => $requested->json('data.version'),
            'expected_grand_total' => 10,
        ])->assertCreated();

        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_two_confirmed_carts_cannot_reserve_the_last_unit(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 1);
        $otherConversation = $this->conversationFor($this->customer, '+15550002222');

        $first = $this->openCart();
        $second = $this->openCartFor($otherConversation);

        $firstAdded = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $first->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertOk();

        $secondAdded = $this->api()->postJson($this->cartPathFor($otherConversation, '/items'), [
            'version' => $second->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertOk();

        $firstRequested = $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $firstAdded->json('data.version'),
            'notify' => false,
        ])->assertOk();
        $secondRequested = $this->api()->postJson($this->cartPathFor($otherConversation, '/request-confirmation'), [
            'version' => $secondAdded->json('data.version'),
            'notify' => false,
        ])->assertOk();

        $this->api()->postJson($this->cartPath('/confirm'), [
            'version' => $firstRequested->json('data.version'),
            'expected_grand_total' => 10,
        ])->assertCreated();

        $this->api()->postJson($this->cartPathFor($otherConversation, '/confirm'), [
            'version' => $secondRequested->json('data.version'),
            'expected_grand_total' => 10,
        ])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, $this->inventoryFor($variant)->reserved_quantity);
    }

    public function test_confirmation_request_is_sent_once_even_when_retried(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $opened = $this->openCart();

        $added = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertOk();

        $this->mock(WhatsAppGateway::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendText')
                ->once()
                ->withArgs(fn (Conversation $conversation, string $body): bool => $conversation->is($this->conversation)
                    && $body === 'Please confirm your order.')
                ->andReturn('wamid.cart-1');
        });

        $requested = $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $added->json('data.version'),
            'notify' => true,
            'message' => 'Please confirm your order.',
        ])->assertOk()
            ->assertJsonPath('data.confirmation_message_id', 'wamid.cart-1');

        $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $requested->json('data.version'),
            'notify' => true,
            'message' => 'Please confirm your order.',
        ])->assertOk()
            ->assertJsonPath('data.confirmation_message_id', 'wamid.cart-1');

        $this->assertNotNull($this->freshCart()->confirmation_sent_at);
    }

    public function test_cart_endpoints_are_forbidden_without_permission(): void
    {
        $outsider = User::factory()->create();
        $outsider->tenants()->attach($this->tenant->getKey(), ['is_owner' => false]);

        $this->actingAs($outsider)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson($this->cartPath())
            ->assertForbidden();
    }

    public function test_reopening_a_pending_cart_allows_editing_again(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $opened = $this->openCart();

        $added = $this->api()->postJson($this->cartPath('/items'), [
            'version' => $opened->json('data.version'),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ])->assertOk();

        $requested = $this->api()->postJson($this->cartPath('/request-confirmation'), [
            'version' => $added->json('data.version'),
            'notify' => false,
        ])->assertOk();

        $reopened = $this->api()->postJson($this->cartPath('/reopen'), [
            'version' => $requested->json('data.version'),
        ])->assertOk()
            ->assertJsonPath('data.status', CartStatus::Draft->value);

        $this->api()->patchJson($this->cartPath(), [
            'version' => $reopened->json('data.version'),
            'tax_rate' => 0.10,
        ])->assertOk()->assertJsonPath('data.grand_total', 11);
    }

    private function api(): static
    {
        return $this->actingAs($this->merchant)->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function conversationFor(Customer $customer, ?string $account = null): Conversation
    {
        return Conversation::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => $customer->getKey(),
            'channel' => 'whatsapp',
            'channel_account' => $account ?? '+15550003333',
            'last_inbound_at' => now(),
        ]);
    }

    private function cartPath(string $suffix = ''): string
    {
        return $this->cartPathFor($this->conversation, $suffix);
    }

    private function cartPathFor(Conversation $conversation, string $suffix = ''): string
    {
        return '/api/v1/tenant/conversations/'.$conversation->getKey().'/cart'.$suffix;
    }

    private function openCart(): TestResponse
    {
        return $this->openCartFor($this->conversation);
    }

    private function openCartFor(Conversation $conversation): TestResponse
    {
        return $this->api()->postJson($this->cartPathFor($conversation))->assertCreated();
    }

    private function freshCart(): Cart
    {
        return Cart::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function inventoryFor(ProductVariant $variant): Inventory
    {
        return Inventory::query()->withoutGlobalScopes()
            ->where('product_variant_id', $variant->getKey())
            ->where('store_id', $this->store->getKey())
            ->firstOrFail();
    }
}
