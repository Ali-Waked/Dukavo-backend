<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversations\ConversationService;
use App\Services\Inventory\InventoryService;
use App\Services\Marketing\ContactService;
use App\Services\Marketing\WaitlistService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notification dispatch: who is told, on which channels, and exactly once.
 *
 * These tests pin the promises the dispatcher makes to the rest of the domain:
 * the in-app copy is written regardless of outbound delivery, a channel with no
 * working transport is recorded honestly as skipped rather than faked, a
 * recurring alert obeys its cooldown, recipients are scoped to their tenant, and
 * the recipient's own preferences decide the outbound channels.
 */
class NotificationDispatchTest extends TestCase
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
    }

    public function test_creating_an_order_writes_an_in_app_notification_and_sends_email(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        [$order] = $this->placeOrder();

        $this->assertSame(1, $merchant->notifications()->count());

        $notification = $merchant->notifications()->firstOrFail();
        $this->assertSame(Events::TENANT_ORDER_CREATED, $notification->event_key);
        $this->assertStringContainsString($order->order_number, $notification->title);

        $mail = NotificationDelivery::query()
            ->where('notification_id', $notification->getKey())
            ->where('channel', 'mail')
            ->firstOrFail();

        $this->assertSame(NotificationDeliveryStatus::Sent, $mail->status);
        $this->assertNotNull($mail->sent_at);
    }

    public function test_a_notification_reaches_members_of_the_tenant_only(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $outsider = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->placeOrder();

        $this->assertSame(1, $merchant->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_a_repeated_dispatch_is_recorded_once(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        $intent = new NotificationIntent(
            eventKey: Events::TENANT_ORDER_CREATED,
            tenantId: $this->tenant->getKey(),
            dedupeKey: 'tenant.order.created:order-999',
            data: ['order_number' => 'ORD-000999', 'customer' => 'Aye', 'total' => '10.00'],
            actionUrl: '/orders/ORD-000999',
        );

        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->dispatch($intent);
        $dispatcher->dispatch($intent);

        $this->assertSame(1, $merchant->notifications()->count());
    }

    public function test_low_stock_alert_obeys_its_cooldown(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        $this->stockedProduct($this->tenant, $this->store, 20);

        $inventory = Inventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $inventory->forceFill(['quantity' => 3, 'low_stock_threshold' => 5])->save();

        $this->withinTenant($this->tenant, fn () => app(InventoryService::class)->evaluateLowStock($inventory));
        $this->withinTenant($this->tenant, fn () => app(InventoryService::class)->evaluateLowStock($inventory));

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_INVENTORY_LOW_STOCK)->count(),
        );
    }

    public function test_whatsapp_delivery_is_recorded_as_skipped_when_not_configured(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        $this->placeOrder();

        $notification = $merchant->notifications()->firstOrFail();

        $whatsapp = NotificationDelivery::query()
            ->where('notification_id', $notification->getKey())
            ->where('channel', 'whatsapp')
            ->firstOrFail();

        $this->assertSame(NotificationDeliveryStatus::Skipped, $whatsapp->status);
        $this->assertSame('whatsapp_not_configured', $whatsapp->error);
        $this->assertNull($whatsapp->provider_message_id);
    }

    public function test_muting_a_channel_keeps_the_in_app_record_and_skips_outbound(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $merchant->forceFill([
            'notification_preferences' => [
                'email' => ['order_updates' => false],
                'whatsapp' => ['order_updates' => false],
            ],
        ])->save();

        $this->placeOrder();

        $this->assertSame(1, $merchant->notifications()->count());
        $this->assertSame(0, $merchant->notifications()->firstOrFail()->deliveries()->count());
    }

    public function test_notification_is_localised_to_the_recipient_locale(): void
    {
        $english = $this->memberOf($this->tenant, 'merchant');
        $arabic = $this->memberOf($this->tenant, 'merchant');
        $arabic->forceFill(['locale' => 'ar'])->save();

        $this->placeOrder();

        $englishTitle = $english->notifications()->firstOrFail()->title;
        $arabicTitle = $arabic->notifications()->firstOrFail()->title;

        $this->assertNotSame($englishTitle, $arabicTitle);
        $this->assertStringContainsString('طلب', $arabicTitle);
    }

    public function test_platform_event_notifies_operators_not_merchants(): void
    {
        $operator = $this->platformUser();
        $merchant = $this->memberOf($this->tenant, 'merchant');

        app(WaitlistService::class)->join([
            'email' => 'lead@example.test',
            'name' => 'New Lead',
        ]);

        $this->assertSame(1, $operator->notifications()->count());
        $this->assertSame(0, $merchant->notifications()->count());
    }

    public function test_creating_a_workspace_notifies_platform_operators(): void
    {
        $operator = $this->platformUser();
        $owner = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/onboarding/tenant', [
                'name' => 'Acme Traders',
                'store_name' => 'Main',
            ])
            ->assertCreated();

        $this->assertSame(1, $operator->notifications()->count());
        $this->assertSame(Events::PLATFORM_TENANT_ONBOARDED, $operator->notifications()->firstOrFail()->event_key);
    }

    public function test_a_contact_message_notifies_platform_operators(): void
    {
        $operator = $this->platformUser();

        app(ContactService::class)->submit([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'subject' => 'Partnership',
            'message' => 'Hello Dukavo.',
        ]);

        $this->assertSame(1, $operator->notifications()->count());
        $this->assertSame(Events::PLATFORM_CONTACT_RECEIVED, $operator->notifications()->firstOrFail()->event_key);
    }

    public function test_an_inbound_message_alerts_the_inbox_but_an_outbound_reply_does_not(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        $conversation = $this->withinTenant($this->tenant, fn () => app(ConversationService::class)->openForCustomer(
            Customer::factory()->create(),
            'whatsapp',
            '100200300',
        ));

        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)->addMessage(
            $conversation,
            MessageDirection::Inbound,
            ['body' => 'Do you have this in blue?'],
        ));

        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)->addMessage(
            $conversation,
            MessageDirection::Outbound,
            ['body' => 'Let me check.'],
        ));

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_CONVERSATION_MESSAGE_RECEIVED)->count(),
        );
    }

    /**
     * @return array{0: Order}
     */
    private function placeOrder(): array
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, 20);

        $order = $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        return [$order];
    }
}
