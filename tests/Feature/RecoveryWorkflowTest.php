<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\RecoveryEventType;
use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RecoveryEvent;
use App\Models\RecoveryOpportunity;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Conversations\ConversationService;
use App\Services\Orders\OrderService;
use App\Services\Recovery\RecoveryDetectionService;
use App\Services\Recovery\RecoveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\RecordingLanguageModel;
use Tests\TestCase;

class RecoveryWorkflowTest extends TestCase
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
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->store = $this->withinTenant(
            $this->tenant,
            fn (): Store => Store::query()->where('tenant_id', $this->tenant->getKey())->firstOrFail(),
        );
    }

    public function test_detection_creates_one_abandoned_cart_opportunity_and_is_idempotent(): void
    {
        $customer = $this->customer();
        $conversation = $this->conversation($customer);
        $cart = $this->staleCart($conversation, $customer);

        $stats = $this->withinTenant($this->tenant, fn (): array => app(RecoveryDetectionService::class)
            ->detectForTenant($this->tenant));

        $this->assertSame(1, $stats['created']);

        $opportunity = $this->opportunityQuery()->firstOrFail();

        $this->assertSame(RecoveryReason::AbandonedCart, $opportunity->reason);
        $this->assertSame(RecoveryOpportunityStatus::Eligible, $opportunity->status);
        $this->assertSame((float) $cart->grand_total, (float) $opportunity->estimated_value);
        $this->assertSame('cart:'.$cart->getKey(), $opportunity->dedupe_key);
        $this->assertDatabaseHas('recovery_events', [
            'recovery_opportunity_id' => $opportunity->getKey(),
            'type' => RecoveryEventType::Detected->value,
        ]);

        $stats = $this->withinTenant($this->tenant, fn (): array => app(RecoveryDetectionService::class)
            ->detectForTenant($this->tenant));

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $this->opportunityQuery()->count());
    }

    public function test_detection_does_not_touch_fresh_closed_or_opted_out_carts(): void
    {
        $freshCustomer = $this->customer('fresh@example.test');
        $freshConversation = $this->conversation($freshCustomer);
        $this->staleCart($freshConversation, $freshCustomer, hoursOld: 2);

        $closedCustomer = $this->customer('closed@example.test');
        $closedConversation = $this->conversation($closedCustomer, ['status' => ConversationStatus::Closed]);
        $this->staleCart($closedConversation, $closedCustomer);

        $optedOutCustomer = $this->customer('optout@example.test');
        $optedOutCustomer->forceFill(['whatsapp_opt_out_at' => now()->subDay()])->save();
        $optedOutConversation = $this->conversation($optedOutCustomer);
        $this->staleCart($optedOutConversation, $optedOutCustomer);

        $orderedCustomer = $this->customer('ordered@example.test');
        $orderedConversation = $this->conversation($orderedCustomer);
        $this->staleCart($orderedConversation, $orderedCustomer);
        $this->withinTenant($this->tenant, fn () => Order::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => $orderedCustomer->getKey(),
            'conversation_id' => $orderedConversation->getKey(),
        ]));

        $stats = $this->withinTenant($this->tenant, fn (): array => app(RecoveryDetectionService::class)
            ->detectForTenant($this->tenant));

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $this->opportunityQuery()->count());
    }

    public function test_detection_finds_an_unanswered_inquiry_but_not_an_answered_one(): void
    {
        $customer = $this->customer('unanswered@example.test');
        $conversation = $this->conversation($customer, ['last_inbound_at' => now()->subHours(30)]);
        $this->inbound($conversation, 'Is this still available?', now()->subHours(30));

        $answeredCustomer = $this->customer('answered@example.test');
        $answeredConversation = $this->conversation($answeredCustomer, ['last_inbound_at' => now()->subHours(30)]);
        $this->inbound($answeredConversation, 'Do you deliver?', now()->subHours(30));
        $this->withinTenant($this->tenant, fn () => $answeredConversation->messages()->create([
            'tenant_id' => $this->tenant->getKey(),
            'direction' => MessageDirection::Outbound,
            'type' => MessageType::Text,
            'body' => 'Yes, we do.',
            'delivery_status' => 'sent',
            'sent_at' => now()->subHours(29),
        ]));

        $stats = $this->withinTenant($this->tenant, fn (): array => app(RecoveryDetectionService::class)
            ->detectForTenant($this->tenant));

        $this->assertSame(1, $stats['created']);

        $opportunity = $this->opportunityQuery()->firstOrFail();

        $this->assertSame(RecoveryReason::UnansweredInquiry, $opportunity->reason);
        $this->assertNull($opportunity->estimated_value);
        $this->assertSame($conversation->getKey(), $opportunity->conversation_id);
        $this->assertStringStartsWith('conversation:'.$conversation->getKey().':inbound:', $opportunity->dedupe_key);
    }

    public function test_contact_sends_only_after_a_human_request_and_records_the_funnel(): void
    {
        $customer = $this->customer('contact@example.test');
        $conversation = $this->conversation($customer, ['last_inbound_at' => now()]);
        $this->inbound($conversation, 'I was about to order', now());
        $opportunity = $this->opportunity($conversation, $customer);

        $this->mock(WhatsAppGateway::class, function (MockInterface $mock) use ($conversation): void {
            $mock->shouldReceive('sendText')
                ->once()
                ->andReturnUsing(function (Conversation $sent, string $body, ?string $key) use ($conversation): string {
                    $this->assertTrue($sent->is($conversation));
                    $this->assertSame('Just checking in about your order.', $body);

                    $this->app->make(ConversationService::class)->addMessage($sent, MessageDirection::Outbound, [
                        'user_id' => $this->merchant->getKey(),
                        'type' => MessageType::Text,
                        'body' => $body,
                        'external_id' => 'wamid.recovery-1',
                        'idempotency_key' => $key,
                        'delivery_status' => 'sent',
                        'sent_at' => now(),
                    ]);

                    return 'wamid.recovery-1';
                });
        });

        $response = $this->api()->postJson($this->path($opportunity, '/contact'), [
            'mode' => 'text',
            'body' => 'Just checking in about your order.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', RecoveryOpportunityStatus::Contacted->value)
            ->assertJsonPath('data.contact_attempts', 1);

        $fresh = $this->opportunityQuery()->findOrFail($opportunity->getKey());

        $this->assertNotNull($fresh->last_contacted_at);
        $this->assertNotNull($fresh->next_follow_up_at);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->getKey(),
            'external_id' => 'wamid.recovery-1',
            'body' => 'Just checking in about your order.',
        ]);
        $this->assertSame(1, RecoveryEvent::query()->withoutGlobalScopes()
            ->where('recovery_opportunity_id', $opportunity->getKey())
            ->where('type', RecoveryEventType::Contacted->value)
            ->count());
    }

    public function test_contact_is_refused_outside_the_whatsapp_window_and_for_opted_out_customers(): void
    {
        $customer = $this->customer('window@example.test');
        $conversation = $this->conversation($customer, ['last_inbound_at' => now()->subDays(2)]);
        $this->inbound($conversation, 'Maybe later', now()->subDays(2));
        $outsideWindow = $this->opportunity($conversation, $customer);

        $this->api()->postJson($this->path($outsideWindow, '/contact'), [
            'mode' => 'text',
            'body' => 'Are you still interested?',
        ])->assertStatus(409)->assertJsonPath('code', 'whatsapp_messaging_window_closed');

        $optedOutCustomer = $this->customer('cannot@example.test');
        $optedOutCustomer->forceFill(['whatsapp_opt_out_at' => now()])->save();
        $optedOutConversation = $this->conversation($optedOutCustomer, ['last_inbound_at' => now()]);
        $this->inbound($optedOutConversation, 'Hello', now());
        $optedOut = $this->opportunity($optedOutConversation, $optedOutCustomer);

        $this->api()->postJson($this->path($optedOut, '/contact'), [
            'mode' => 'text',
            'body' => 'Hello again',
        ])->assertStatus(409)->assertJsonPath('code', 'customer_opted_out');
    }

    public function test_an_inbound_reply_records_response_and_an_opt_out_closes_the_work(): void
    {
        $customer = $this->customer('reply@example.test');
        $conversation = $this->conversation($customer, ['last_inbound_at' => now()->subDay()]);
        $opportunity = $this->opportunity($conversation, $customer);

        $this->inbound($conversation, 'I am still thinking', now());

        $fresh = $this->opportunityQuery()->findOrFail($opportunity->getKey());

        $this->assertNotNull($fresh->responded_at);
        $this->assertSame(1, RecoveryEvent::query()->withoutGlobalScopes()
            ->where('recovery_opportunity_id', $opportunity->getKey())
            ->where('type', RecoveryEventType::Responded->value)
            ->count());

        $this->inbound($conversation, 'STOP', now()->addMinute());

        $fresh = $this->opportunityQuery()->findOrFail($opportunity->getKey());
        $freshCustomer = $this->withinTenant($this->tenant, fn () => Customer::query()->findOrFail($customer->getKey()));

        $this->assertNotNull($freshCustomer->whatsapp_opt_out_at);
        $this->assertSame(RecoveryOpportunityStatus::Dismissed, $fresh->status);
        $this->assertSame(1, RecoveryEvent::query()->withoutGlobalScopes()
            ->where('recovery_opportunity_id', $opportunity->getKey())
            ->where('type', RecoveryEventType::OptedOut->value)
            ->count());
    }

    public function test_a_real_order_is_attributed_and_cancellation_releases_the_revenue(): void
    {
        $customer = $this->customer('order@example.test');
        $conversation = $this->conversation($customer, ['last_inbound_at' => now()]);
        $opportunity = $this->opportunity($conversation, $customer);
        $order = $this->withinTenant($this->tenant, fn () => Order::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => $customer->getKey(),
            'conversation_id' => $conversation->getKey(),
            'grand_total' => 84.50,
            'currency' => 'USD',
        ]));

        $this->withinTenant($this->tenant, fn () => app(RecoveryService::class)->attributeOrder($order));

        $fresh = $this->opportunityQuery()->findOrFail($opportunity->getKey());

        $this->assertSame(RecoveryOpportunityStatus::Recovered, $fresh->status);
        $this->assertSame($order->getKey(), $fresh->order_id);
        $this->assertNotNull($fresh->recovered_at);

        $this->withinTenant($this->tenant, fn () => app(OrderService::class)
            ->transition($order, OrderStatus::Cancelled));

        $fresh = $this->opportunityQuery()->findOrFail($opportunity->getKey());

        $this->assertSame(RecoveryOpportunityStatus::Eligible, $fresh->status);
        $this->assertNull($fresh->order_id);
        $this->assertNull($fresh->recovered_at);
        $this->assertSame(1, RecoveryEvent::query()->withoutGlobalScopes()
            ->where('recovery_opportunity_id', $opportunity->getKey())
            ->where('type', RecoveryEventType::AttributionReleased->value)
            ->count());
    }

    public function test_ai_draft_is_returned_for_review_without_sending_anything(): void
    {
        $customer = $this->customer('ai@example.test');
        $conversation = $this->conversation($customer, ['last_inbound_at' => now()->subHours(30)]);
        $this->inbound($conversation, 'هل ما زال العرض متاحًا؟', now()->subHours(30));
        $opportunity = $this->opportunity($conversation, $customer);

        $this->configureAi();
        $this->fakeAi(new AiCompletion(
            text: '',
            structured: [
                'suggested_reply' => 'أهلًا بك، هل تود أن أساعدك في إكمال طلبك؟',
                'language' => 'arabic',
                'tone' => 'friendly',
                'rationale' => 'The customer asked whether the offer is still available.',
                'needs_human' => false,
                'handoff_reason' => null,
                'risk_flags' => [],
            ],
            model: 'fake-model',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
        ));

        $response = $this->api()->postJson($this->path($opportunity, '/ai-draft'), [
            'instruction' => 'Keep it short.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.suggested_reply', 'أهلًا بك، هل تود أن أساعدك في إكمال طلبك؟')
            ->assertJsonPath('data.needs_human', false);

        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->getKey())
            ->where('direction', MessageDirection::Outbound->value)
            ->count());
        $this->assertSame(RecoveryOpportunityStatus::Eligible, $this->opportunityQuery()
            ->findOrFail($opportunity->getKey())->status);
    }

    private function api(): static
    {
        return $this->actingAs($this->merchant, 'sanctum')->withHeader('X-Tenant', $this->tenant->slug);
    }

    private function path(RecoveryOpportunity $opportunity, string $suffix = ''): string
    {
        return '/api/v1/tenant/recovery-opportunities/'.$opportunity->getKey().$suffix;
    }

    private function customer(string $email = 'customer@example.test'): Customer
    {
        return $this->withinTenant($this->tenant, fn (): Customer => Customer::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Recovery Customer',
            'phone' => '+15550009999',
            'email' => $email,
            'status' => 'active',
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function conversation(Customer $customer, array $attributes = []): Conversation
    {
        return $this->withinTenant($this->tenant, fn (): Conversation => Conversation::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => $customer->getKey(),
            'channel' => 'whatsapp',
            'channel_account' => '+15550003333',
        ] + $attributes));
    }

    private function inbound(Conversation $conversation, string $body, Carbon $at): ConversationMessage
    {
        return $this->withinTenant($this->tenant, fn (): ConversationMessage => $conversation->messages()->create([
            'tenant_id' => $this->tenant->getKey(),
            'direction' => MessageDirection::Inbound,
            'type' => MessageType::Text,
            'body' => $body,
            'delivery_status' => 'received',
            'sent_at' => $at,
        ]));
    }

    private function staleCart(Conversation $conversation, Customer $customer, int $hoursOld = 30): Cart
    {
        ['product' => $product, 'variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $cart = $this->withinTenant($this->tenant, fn (): Cart => Cart::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'conversation_id' => $conversation->getKey(),
            'customer_id' => $customer->getKey(),
            'status' => CartStatus::Draft,
            'currency' => 'USD',
            'subtotal' => 20,
            'grand_total' => 20,
        ]));

        $this->withinTenant($this->tenant, fn () => $cart->items()->create([
            'tenant_id' => $this->tenant->getKey(),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'name' => 'Test product',
            'sku' => 'SKU-'.$variant->getKey(),
            'unit_price' => 10,
            'quantity' => 2,
            'discount_total' => 0,
            'line_total' => 20,
        ]));

        DB::table('carts')->where('id', $cart->getKey())->update([
            'updated_at' => now()->subHours($hoursOld),
        ]);

        return $cart->refresh();
    }

    private function opportunity(Conversation $conversation, Customer $customer): RecoveryOpportunity
    {
        return $this->withinTenant($this->tenant, fn (): RecoveryOpportunity => RecoveryOpportunity::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'conversation_id' => $conversation->getKey(),
            'customer_id' => $customer->getKey(),
            'reason' => RecoveryReason::UnansweredInquiry,
            'dedupe_key' => 'test:'.uniqid(),
            'status' => RecoveryOpportunityStatus::Eligible,
            'detected_at' => now()->subHours(2),
            'expires_at' => now()->addDays(30),
        ]));
    }

    /**
     * @return Builder<RecoveryOpportunity>
     */
    private function opportunityQuery(): Builder
    {
        return RecoveryOpportunity::query()->withoutGlobalScopes();
    }

    private function configureAi(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.features.recovery.enabled', true);
        config()->set('ai.features.recovery.daily_per_user', 100);
        config()->set('ai.features.recovery.daily_per_tenant', 100);
        config()->set('ai.features.recovery.daily_tokens_per_user', 1000000);
        config()->set('ai.features.recovery.daily_tokens_per_tenant', 1000000);
        config()->set('ai.provider', 'fake');
        config()->set('ai.fallback_providers', []);
        config()->set('ai.providers.fake', [
            'driver' => 'fake',
            'base_url' => '',
            'api_key' => null,
            'path' => '',
            'model' => 'fake',
            'transport' => [],
        ]);
    }

    private function fakeAi(AiCompletion $completion): RecordingLanguageModel
    {
        $model = new RecordingLanguageModel($completion);

        $this->app->instance(LanguageModel::class, $model);
        $this->app->instance(AiProviderFactory::class, new FakeAiProviderFactory($model));

        return $model;
    }
}
