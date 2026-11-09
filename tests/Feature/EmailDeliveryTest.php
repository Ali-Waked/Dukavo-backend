<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationSeverity;
use App\Jobs\SendNotificationEmail;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Emails\EmailFailureClassifier;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Services\Notifications\Senders\EmailNotificationSender;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Delivery behaviour: what is queued, what is sent, what is retried, and what
 * is deliberately not sent.
 *
 * The tests distinguish the in-app record from the email attempt. A muted
 * preference must still leave a notification, a duplicate business event must
 * not produce a second email, a maintenance notice must not be muteable, and a
 * transport failure must be recorded with enough evidence for an operator to
 * decide whether a retry is meaningful.
 */
class EmailDeliveryTest extends TestCase
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

    public function test_an_order_notification_sends_a_branded_email_to_the_recipient(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        Mail::fake();

        $order = $this->placeOrder();

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($merchant, $order): bool {
            return $mail->hasTo($merchant->email)
                && $mail->locale === 'en'
                && str_contains($mail->envelope()->subject, $order->order_number)
                && str_contains($mail->render(), $order->order_number);
        });

        $notification = $merchant->notifications()->firstOrFail();
        $delivery = NotificationDelivery::query()
            ->where('notification_id', $notification->getKey())
            ->where('channel', NotificationChannel::Mail)
            ->firstOrFail();

        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_email_jobs_are_dispatched_only_after_the_transaction_commits(): void
    {
        $this->memberOf($this->tenant, 'merchant');

        Queue::fake();

        DB::beginTransaction();

        try {
            app(NotificationDispatcher::class)->dispatch($this->intent(Events::TENANT_ORDER_CREATED, 'after-commit'));

            Queue::assertNotPushed(SendNotificationEmail::class);

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        Queue::assertPushed(SendNotificationEmail::class);
    }

    public function test_a_duplicate_business_event_produces_one_notification_and_one_email(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        Mail::fake();

        $intent = $this->intent(Events::TENANT_ORDER_CREATED, 'duplicate-once');

        app(NotificationDispatcher::class)->dispatch($intent);
        app(NotificationDispatcher::class)->dispatch($intent);

        $this->assertSame(1, $merchant->notifications()->count());
        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_a_muted_preference_keeps_the_in_app_record_and_skips_the_email(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $merchant->forceFill([
            'notification_preferences' => ['email' => ['order_updates' => false]],
        ])->save();

        Mail::fake();

        app(NotificationDispatcher::class)->dispatch($this->intent(Events::TENANT_ORDER_CREATED, 'muted'));

        $this->assertSame(1, $merchant->notifications()->count());
        $this->assertSame(
            0,
            NotificationDelivery::query()
                ->where('notification_id', $merchant->notifications()->firstOrFail()->getKey())
                ->where('channel', NotificationChannel::Mail)
                ->count(),
        );
        Mail::assertNothingSent();
    }

    public function test_a_maintenance_notice_cannot_be_muted_but_product_news_can(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $merchant->forceFill([
            'notification_preferences' => [
                // Marketing is opt-in on every channel. Keep the in-app copy
                // visible and mute only email so this test isolates the email
                // preference rather than the safe default.
                'in_app' => ['product_news' => true],
                'email' => ['product_news' => false, 'order_updates' => false],
            ],
        ])->save();

        Queue::fake();

        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_ANNOUNCEMENT_PRODUCT,
            tenantId: (int) $this->tenant->getKey(),
            dedupeKey: 'announcement.product:1',
            data: ['title' => 'New report filters', 'summary' => 'Filter reports by store.'],
        ));

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_ANNOUNCEMENT_PRODUCT)->count(),
        );
        $this->assertSame(
            0,
            NotificationDelivery::query()
                ->where('event_key', Events::TENANT_ANNOUNCEMENT_PRODUCT)
                ->where('channel', NotificationChannel::Mail)
                ->count(),
        );

        $dispatcher->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_ANNOUNCEMENT_MAINTENANCE,
            tenantId: (int) $this->tenant->getKey(),
            dedupeKey: 'announcement.maintenance:1',
            data: ['title' => 'Scheduled maintenance', 'summary' => 'Dukavo will be briefly unavailable.'],
            severity: NotificationSeverity::Warning,
        ));

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_ANNOUNCEMENT_MAINTENANCE)->count(),
        );
        Queue::assertPushed(SendNotificationEmail::class);
    }

    public function test_a_temporary_transport_failure_is_recorded_and_rethrown_for_retry(): void
    {
        $delivery = $this->queuedDelivery();

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('Connection timed out while contacting the provider.'));

        $job = new SendNotificationEmail((int) $delivery->getKey());

        try {
            $job->handle(app(EmailNotificationSender::class), app(EmailFailureClassifier::class));
            $this->fail('A temporary failure should be rethrown so the queue retries.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(EmailFailureClassifier::TEMPORARY, $delivery->failure_type);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->failed_at);
        $this->assertStringContainsString('timed out', (string) $delivery->error);
    }

    public function test_a_permanent_transport_failure_is_recorded_once_without_retrying(): void
    {
        $delivery = $this->queuedDelivery();

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('550 5.1.1 invalid recipient'));

        $job = new SendNotificationEmail((int) $delivery->getKey());
        $job->handle(app(EmailNotificationSender::class), app(EmailFailureClassifier::class));

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(EmailFailureClassifier::PERMANENT, $delivery->failure_type);
        $this->assertTrue($delivery->isPermanentFailure());
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_an_exhausted_temporary_failure_stops_retrying_and_stays_recorded(): void
    {
        $delivery = $this->queuedDelivery();

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('Provider is temporarily unavailable.'));

        $job = new SendNotificationEmail((int) $delivery->getKey());
        $job->tries = 1;
        $job->handle(app(EmailNotificationSender::class), app(EmailFailureClassifier::class));

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(EmailFailureClassifier::TEMPORARY, $delivery->failure_type);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_delivery_that_already_succeeded_is_not_sent_again(): void
    {
        $delivery = $this->queuedDelivery();
        $delivery->markSent('provider-message-1');

        Mail::fake();

        $job = new SendNotificationEmail((int) $delivery->getKey());
        $job->handle(app(EmailNotificationSender::class), app(EmailFailureClassifier::class));

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame('provider-message-1', $delivery->provider_message_id);
        Mail::assertNothingSent();
    }

    private function queuedDelivery(): NotificationDelivery
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        $notification = Notification::factory()->forUser($merchant)->create([
            'tenant_id' => $this->tenant->getKey(),
            'event_key' => Events::TENANT_ORDER_CREATED,
            'title' => 'New order ORD-000001',
            'body' => 'A new order was placed.',
            'data' => ['order_number' => 'ORD-000001', 'customer' => 'Amina', 'total' => '10.00 USD'],
        ]);

        return NotificationDelivery::factory()->create([
            'notification_id' => $notification->getKey(),
            'event_key' => Events::TENANT_ORDER_CREATED,
            'recipient_type' => $merchant->getMorphClass(),
            'recipient_id' => $merchant->getKey(),
            'channel' => NotificationChannel::Mail,
            'status' => NotificationDeliveryStatus::Queued,
            'attempts' => 0,
        ]);
    }

    private function intent(string $eventKey, string $dedupe): NotificationIntent
    {
        return new NotificationIntent(
            eventKey: $eventKey,
            tenantId: (int) $this->tenant->getKey(),
            dedupeKey: $dedupe,
            data: [
                'order_number' => $dedupe === 'after-commit' ? 'ORD-AFTER-COMMIT' : 'ORD-DUPLICATE',
                'customer' => 'Layla Hassan',
                'total' => '249.00 USD',
            ],
            actionUrl: '/orders/ORD-000001',
        );
    }

    private function placeOrder(): Order
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, 20);

        return $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));
    }
}
