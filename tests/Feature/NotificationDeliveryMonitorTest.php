<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationPush;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Platform delivery monitoring and safe retry.
 *
 * The screen exists so an operator can answer "did this actually go out?" across
 * every tenant without reading private message bodies. A retry is deliberately
 * narrow: only a recorded transient failure on a channel that has a transport,
 * and only once — the row leaves `failed` before another request can see it.
 */
class NotificationDeliveryMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $merchant;

    private Tenant $tenant;

    private Notification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->platformUser('super-admin');
        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant);

        $this->notification = Notification::factory()->forUser($this->merchant)->create([
            'tenant_id' => $this->tenant->getKey(),
            'event_key' => 'tenant.order.created',
        ]);
    }

    public function test_a_merchant_cannot_reach_delivery_monitoring(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->merchant, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries')
            ->assertForbidden();

        $this->actingAs($this->merchant, 'sanctum')
            ->getJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}")
            ->assertForbidden();

        $this->actingAs($this->merchant, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertForbidden();
    }

    public function test_the_index_shows_recipient_tenant_status_and_never_the_payload(): void
    {
        $delivery = $this->delivery([
            'payload' => ['secret' => 'merchant-private'],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.id', $delivery->getKey())
            ->assertJsonPath('data.0.channel', 'mail')
            ->assertJsonPath('data.0.recipient.name', $this->merchant->name)
            ->assertJsonPath('data.0.tenant.name', $this->tenant->name)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.retryable', true);

        $row = $response->json('data.0');

        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('notification', $row);
        $this->assertSame('m•••@example.test', $row['recipient']['ref']);
    }

    public function test_filters_narrow_by_status_channel_event_tenant_and_search(): void
    {
        $this->delivery([
            'status' => NotificationDeliveryStatus::Failed,
            'failure_type' => 'temporary',
            'error' => 'smtp timeout',
        ]);

        NotificationDelivery::factory()->create([
            'notification_id' => $this->notification->getKey(),
            'event_key' => 'tenant.order.created',
            'channel' => NotificationChannel::Push,
            'recipient_type' => 'user',
            'recipient_id' => $this->merchant->getKey(),
            'recipient_ref' => 'device-token',
            'status' => NotificationDeliveryStatus::Sent,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries?channel=push')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'push');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries?tenant_id='.$this->tenant->getKey())
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries?event_key=tenant.order.created')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries?search=smtp')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-deliveries?status=delivered')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_transient_mail_failure_can_be_retried_once(): void
    {
        Queue::fake();

        $delivery = $this->delivery([
            'status' => NotificationDeliveryStatus::Failed,
            'failure_type' => 'temporary',
            'error' => 'smtp timeout',
            'failed_at' => now(),
            'attempts' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.retryable', false);

        $fresh = $delivery->fresh();

        $this->assertSame(NotificationDeliveryStatus::Queued, $fresh->status);
        $this->assertNull($fresh->failure_type);
        $this->assertNull($fresh->failed_at);

        Queue::assertPushed(SendNotificationEmail::class, function (SendNotificationEmail $job) use ($delivery): bool {
            return $job->deliveryId === (int) $delivery->getKey();
        });

        // The row is no longer failed, so a double-click is refused rather than
        // enqueuing a second send.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'delivery_not_retryable');
    }

    public function test_a_transient_push_failure_dispatches_the_push_job(): void
    {
        Queue::fake();

        $delivery = $this->delivery([
            'channel' => NotificationChannel::Push,
            'status' => NotificationDeliveryStatus::Failed,
            'failure_type' => 'temporary',
            'error' => 'FCM unavailable',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(SendNotificationPush::class);
    }

    public function test_a_permanent_failure_is_refused(): void
    {
        $delivery = $this->delivery([
            'status' => NotificationDeliveryStatus::Failed,
            'failure_type' => 'permanent',
            'error' => 'mailbox does not exist',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'delivery_permanent_failure');
    }

    public function test_a_non_failed_delivery_is_refused(): void
    {
        $delivery = $this->delivery([
            'status' => NotificationDeliveryStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'delivery_not_retryable');
    }

    public function test_whatsapp_has_no_retry_transport(): void
    {
        $delivery = $this->delivery([
            'channel' => NotificationChannel::WhatsApp,
            'status' => NotificationDeliveryStatus::Failed,
            'failure_type' => 'temporary',
            'error' => 'provider unavailable',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-deliveries/{$delivery->getKey()}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'delivery_channel_not_retryable');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(array $attributes = []): NotificationDelivery
    {
        return NotificationDelivery::factory()->create([
            'notification_id' => $this->notification->getKey(),
            'event_key' => 'tenant.order.created',
            'channel' => NotificationChannel::Mail,
            'recipient_type' => 'user',
            'recipient_id' => $this->merchant->getKey(),
            'recipient_ref' => 'm•••@example.test',
            'status' => NotificationDeliveryStatus::Failed,
            'failure_type' => 'temporary',
            'error' => 'smtp timeout',
            'failed_at' => now(),
            ...$attributes,
        ]);
    }
}
