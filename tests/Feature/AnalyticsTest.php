<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecoveryOpportunity;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Executive analytics must be useful and honest at the same time.
 *
 * These tests pin down the tenant/store/currency scope, the shared revenue
 * definition, comparison windows, zero-filled series, and each derived metric.
 * Metrics the stored data cannot support must say so instead of returning an
 * invented number.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore(['currency' => 'USD', 'timezone' => 'UTC']);
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->user = $this->memberOf($this->tenant);
    }

    public function test_it_requires_authentication_and_the_dashboard_permission(): void
    {
        $this->getJson('/api/v1/tenant/analytics')->assertStatus(401);

        $withoutRole = User::factory()->create();
        $withoutRole->tenants()->attach($this->tenant->getKey());

        $this->actingAs($withoutRole)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/analytics')
            ->assertForbidden();
    }

    public function test_revenue_uses_recognized_orders_and_compares_with_the_preceding_period(): void
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
        ]);

        $this->order(['grand_total' => 100, 'subtotal' => 100, 'customer_id' => $customer->getKey(), 'placed_at' => '2026-06-02 10:00:00']);
        $this->order(['grand_total' => 30, 'subtotal' => 30, 'customer_id' => $customer->getKey(), 'placed_at' => '2026-06-10 10:00:00']);
        $this->order(['grand_total' => 40, 'subtotal' => 40, 'status' => OrderStatus::Cancelled, 'placed_at' => '2026-06-03 10:00:00']);
        $this->order(['grand_total' => 20, 'subtotal' => 20, 'payment_status' => PaymentStatus::Refunded, 'placed_at' => '2026-06-04 10:00:00']);
        $this->order(['grand_total' => 50, 'subtotal' => 50, 'placed_at' => '2026-05-20 10:00:00']);

        $response = $this->analytics([
            'from' => '2026-06-01',
            'until' => '2026-06-15',
        ])->assertOk();

        $response
            ->assertJsonPath('data.range.from', '2026-06-01')
            ->assertJsonPath('data.range.until', '2026-06-15')
            ->assertJsonPath('data.comparison.from', '2026-05-17')
            ->assertJsonPath('data.comparison.until', '2026-05-31')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.kpis.0.key', 'revenue')
            ->assertJsonPath('data.kpis.0.value', 130)
            ->assertJsonPath('data.kpis.0.previous_value', 50)
            ->assertJsonPath('data.kpis.0.change_percent', 160)
            ->assertJsonPath('data.kpis.0.direction', 'up')
            ->assertJsonPath('data.kpis.1.value', 2)
            ->assertJsonPath('data.kpis.2.value', 4)
            ->assertJsonPath('data.kpis.3.value', 65)
            ->assertJsonPath('data.customer_repeat_purchase_rate.total_customers_with_orders', 1)
            ->assertJsonPath('data.customer_repeat_purchase_rate.repeat_customers', 1)
            ->assertJsonPath('data.customer_repeat_purchase_rate.repeat_purchase_rate_percent', 100);

        $statuses = collect($response->json('data.order_status_distribution'))->keyBy('status');
        $this->assertSame(3, $statuses['delivered']['count']);
        $this->assertSame(1, $statuses['cancelled']['count']);
    }

    public function test_store_currency_and_tenant_filters_are_applied_on_top_of_isolation(): void
    {
        $secondStore = Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Second store',
        ]);
        $foreignTenant = $this->tenantWithStore();
        $foreignStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $foreignTenant->getKey())
            ->firstOrFail();

        $this->order(['grand_total' => 80, 'subtotal' => 80]);
        $this->order(['grand_total' => 20, 'subtotal' => 20, 'store_id' => $secondStore->getKey()]);
        $this->order(['grand_total' => 40, 'subtotal' => 40, 'currency' => 'EUR']);

        Order::factory()->create([
            'tenant_id' => $foreignTenant->getKey(),
            'store_id' => $foreignStore->getKey(),
            'currency' => 'USD',
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Paid,
            'subtotal' => 999,
            'grand_total' => 999,
            'placed_at' => now(),
        ]);

        $this->analytics(['from' => now()->toDateString(), 'until' => now()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.kpis.0.value', 100)
            ->assertJsonPath('data.kpis.2.value', 2);

        $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
            'store_id' => $secondStore->getKey(),
        ])->assertOk()
            ->assertJsonPath('data.kpis.0.value', 20)
            ->assertJsonPath('data.context.store.id', $secondStore->getKey());

        $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
            'currency' => 'EUR',
        ])->assertOk()
            ->assertJsonPath('data.kpis.0.value', 40)
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_series_is_zero_filled_across_the_selected_range(): void
    {
        $this->order(['grand_total' => 60, 'subtotal' => 60, 'placed_at' => '2026-06-02 12:00:00']);

        $response = $this->analytics([
            'from' => '2026-06-01',
            'until' => '2026-06-04',
        ])->assertOk();

        $series = $response->json('data.sales_trends');

        $this->assertCount(4, $series);
        $this->assertSame(['2026-06-01', 0, 0], [$series[0]['date'], $series[0]['revenue'], $series[0]['orders']]);
        $this->assertSame(['2026-06-02', 60, 1], [$series[1]['date'], $series[1]['revenue'], $series[1]['orders']]);
        $this->assertSame(0, $response->json('data.orders_over_time.3.orders'));
    }

    public function test_product_and_store_performance_share_the_same_recognized_sales(): void
    {
        $order = $this->order(['grand_total' => 90, 'subtotal' => 100, 'discount_total' => 10]);
        $this->item($order, [
            'name' => 'Cotton shirt',
            'sku' => 'SHIRT-1',
            'unit_price' => 50,
            'unit_cost' => 20,
            'cost_currency' => 'USD',
            'quantity' => 2,
            'discount_total' => 10,
            'line_total' => 90,
        ]);

        $response = $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
        ])->assertOk();

        $response
            ->assertJsonPath('data.product_performance.0.name', 'Cotton shirt')
            ->assertJsonPath('data.product_performance.0.units_sold', 2)
            ->assertJsonPath('data.product_performance.0.revenue', 90)
            ->assertJsonPath('data.product_performance.0.gross_profit', 50)
            ->assertJsonPath('data.store_performance.0.store_id', $this->store->getKey())
            ->assertJsonPath('data.store_performance.0.revenue', 90)
            ->assertJsonPath('data.financial_margin.measurable', true)
            ->assertJsonPath('data.financial_margin.summary.gross_profit', 50);
    }

    public function test_customer_repeat_rate_ignores_guest_cancelled_and_refunded_orders(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        $this->order(['customer_id' => $customer->getKey()]);
        $this->order(['customer_id' => $customer->getKey()]);
        $this->order(['customer_id' => $customer->getKey(), 'status' => OrderStatus::Cancelled]);
        $this->order(['customer_id' => $customer->getKey(), 'payment_status' => PaymentStatus::Refunded]);
        $this->order(['customer_id' => null]);

        $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.customer_repeat_purchase_rate.total_customers_with_orders', 1)
            ->assertJsonPath('data.customer_repeat_purchase_rate.repeat_customers', 1);
    }

    public function test_conversation_response_time_uses_the_first_non_internal_reply(): void
    {
        $answered = $this->conversation('2026-06-02 10:00:00');
        $this->message($answered, MessageDirection::Inbound, '2026-06-02 10:00:00');
        $this->message($answered, MessageDirection::Outbound, '2026-06-02 10:01:00', MessageType::Note);
        $this->message($answered, MessageDirection::Outbound, '2026-06-02 10:05:00');

        $unanswered = $this->conversation('2026-06-03 10:00:00');
        $this->message($unanswered, MessageDirection::Inbound, '2026-06-03 10:00:00');

        $this->analytics([
            'from' => '2026-06-01',
            'until' => '2026-06-04',
        ])->assertOk()
            ->assertJsonPath('data.conversation_response_metrics.measurable', true)
            ->assertJsonPath('data.conversation_response_metrics.conversations_analyzed', 2)
            ->assertJsonPath('data.conversation_response_metrics.conversations_with_first_response', 1)
            ->assertJsonPath('data.conversation_response_metrics.response_rate_percent', 50)
            ->assertJsonPath('data.conversation_response_metrics.avg_first_response_minutes', 5);
    }

    public function test_recovery_funnel_uses_linked_recognized_orders_for_recovered_revenue(): void
    {
        $conversation = $this->conversation('2026-06-02 10:00:00');
        $recoveredOrder = $this->order([
            'grand_total' => 200,
            'subtotal' => 200,
            'placed_at' => '2026-06-03 10:00:00',
        ]);

        $this->opportunity($conversation, [
            'status' => RecoveryOpportunityStatus::Recovered,
            'order_id' => $recoveredOrder->getKey(),
            'estimated_value' => 180,
            'contact_attempts' => 1,
            'responded_at' => '2026-06-03 09:00:00',
            'recovered_at' => '2026-06-03 10:00:00',
            'dedupe_key' => 'recovered-1',
        ]);
        $this->opportunity($conversation, [
            'status' => RecoveryOpportunityStatus::Eligible,
            'estimated_value' => 50,
            'dedupe_key' => 'open-1',
        ]);
        $this->opportunity($conversation, [
            'status' => RecoveryOpportunityStatus::Dismissed,
            'dedupe_key' => 'dismissed-1',
        ]);

        $this->analytics([
            'from' => '2026-06-01',
            'until' => '2026-06-04',
        ])->assertOk()
            ->assertJsonPath('data.sales_recovery_funnel.measurable', true)
            ->assertJsonPath('data.sales_recovery_funnel.funnel.0.count', 3)
            ->assertJsonPath('data.sales_recovery_funnel.funnel.3.count', 1)
            ->assertJsonPath('data.sales_recovery_funnel.open_pipeline_value', 50)
            ->assertJsonPath('data.sales_recovery_funnel.recovered_revenue', 200)
            ->assertJsonPath('data.sales_recovery_funnel.conversion_rate_percent', 33.33);
    }

    public function test_delivery_metrics_count_only_outbound_messages(): void
    {
        $conversation = $this->conversation('2026-06-02 10:00:00');
        $this->message($conversation, MessageDirection::Inbound, '2026-06-02 10:00:00');
        $this->message($conversation, MessageDirection::Outbound, '2026-06-02 10:01:00', deliveryStatus: 'delivered');
        $this->message($conversation, MessageDirection::Outbound, '2026-06-02 10:02:00', deliveryStatus: 'read');
        $this->message($conversation, MessageDirection::Outbound, '2026-06-02 10:03:00', deliveryStatus: 'failed');

        $this->analytics([
            'from' => '2026-06-01',
            'until' => '2026-06-04',
        ])->assertOk()
            ->assertJsonPath('data.message_delivery_metrics.total_messages', 3)
            ->assertJsonPath('data.message_delivery_metrics.delivered_or_read', 2)
            ->assertJsonPath('data.message_delivery_metrics.failed', 1)
            ->assertJsonPath('data.message_delivery_metrics.failure_rate_percent', 33.33);
    }

    public function test_stock_health_is_a_current_snapshot_and_filters_by_store(): void
    {
        $this->stockedProduct($this->tenant, $this->store, 20);
        $this->stockedProduct($this->tenant, $this->store, 3);
        $this->stockedProduct($this->tenant, $this->store, 0);

        $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
            'store_id' => $this->store->getKey(),
        ])->assertOk()
            ->assertJsonPath('data.stock_health.summary.total_items', 3)
            ->assertJsonPath('data.stock_health.summary.out_of_stock', 1)
            ->assertJsonPath('data.stock_health.summary.low_stock', 1)
            ->assertJsonPath('data.stock_health.summary.healthy', 1);
    }

    public function test_it_rejects_invalid_ranges_and_foreign_store_ids(): void
    {
        $foreignStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantWithStore()->getKey())
            ->firstOrFail();

        $this->analytics([
            'from' => '2024-01-01',
            'until' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('from');

        $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('until');

        $this->analytics([
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
            'store_id' => $foreignStore->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('store_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => null,
            'currency' => 'USD',
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Paid,
            'placed_at' => now(),
            'subtotal' => 20,
            'discount_total' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'grand_total' => 20,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(Order $order, array $attributes = []): void
    {
        $this->withinTenant($this->tenant, fn () => OrderItem::query()->create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'order_id' => $order->getKey(),
            'product_id' => null,
            'product_variant_id' => null,
            'name' => 'Item',
            'sku' => null,
            'unit_price' => 20,
            'unit_cost' => null,
            'cost_currency' => null,
            'cost_captured_at' => null,
            'quantity' => 1,
            'discount_total' => 0,
            'tax_total' => 0,
            'line_total' => 20,
        ], $attributes)));
    }

    private function conversation(string $createdAt): Conversation
    {
        return Conversation::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => null,
            'created_at' => CarbonImmutable::parse($createdAt),
        ]);
    }

    private function message(
        Conversation $conversation,
        MessageDirection $direction,
        string $sentAt,
        MessageType $type = MessageType::Text,
        string $deliveryStatus = 'sent',
    ): void {
        $this->withinTenant($this->tenant, fn () => ConversationMessage::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'conversation_id' => $conversation->getKey(),
            'direction' => $direction,
            'type' => $type,
            'body' => 'Message',
            'sent_at' => CarbonImmutable::parse($sentAt),
            'is_internal' => $type === MessageType::Note,
            'delivery_status' => $direction === MessageDirection::Outbound ? $deliveryStatus : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function opportunity(Conversation $conversation, array $attributes = []): RecoveryOpportunity
    {
        return RecoveryOpportunity::query()->create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'conversation_id' => $conversation->getKey(),
            'customer_id' => null,
            'status' => RecoveryOpportunityStatus::Eligible,
            'reason' => RecoveryReason::AbandonedCart,
            'dedupe_key' => 'opportunity-'.fake()->unique()->numerify('######'),
            'estimated_value' => 100,
            'currency' => 'USD',
            'contact_attempts' => 0,
            'detected_at' => CarbonImmutable::parse('2026-06-02 10:00:00'),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function analytics(array $query = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/analytics?'.http_build_query($query));
    }
}
