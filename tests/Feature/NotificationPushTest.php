<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationPriority;
use App\Jobs\SendNotificationPush;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Services\Notifications\Push\Contracts\PushTransport;
use App\Services\Notifications\Push\PushDeliveryException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The push fan-out job.
 *
 * FCM credentials are not available in CI, so the transport is replaced with a
 * recorder. These tests pin the behaviour around it: one alert per installation,
 * a rejected token revoked and never retried forever, a transient failure left
 * retryable, and a retry that does not re-alert a phone that already succeeded.
 */
class NotificationPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notification $notification;

    private NotificationDelivery $delivery;

    private RecordingPushTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->notification = Notification::factory()->forUser($this->user)->create([
            'category' => 'orders',
            'priority' => NotificationPriority::High,
            'action_url' => '/orders/ORD-1',
        ]);

        $this->delivery = NotificationDelivery::factory()->create([
            'notification_id' => $this->notification->getKey(),
            'channel' => NotificationChannel::Push,
            'recipient_id' => $this->user->getKey(),
            'status' => NotificationDeliveryStatus::Queued,
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        $this->transport = new RecordingPushTransport;
    }

    public function test_every_active_device_receives_the_alert_once(): void
    {
        $first = $this->device('a');
        $second = $this->device('b');

        $this->job()->handle($this->transport);

        $this->assertCount(2, $this->transport->calls);
        $this->assertSame([$first->getKey(), $second->getKey()], $this->transport->deviceIds());
        $this->assertSame(NotificationDeliveryStatus::Sent, $this->delivery->fresh()->status);
        $this->assertSame('projects/dukavo/messages/'.$first->getKey(), $this->delivery->fresh()->provider_message_id);
        $this->assertSame('orders', $this->transport->calls[0]['category']);
        $this->assertSame('high', $this->transport->calls[0]['priority']->value);
        $this->assertSame($this->notification->getKey(), $this->transport->calls[0]['data']['notification_id']);
    }

    public function test_a_revoked_device_is_not_sent_to(): void
    {
        $active = $this->device('a');
        $revoked = $this->device('b');
        $revoked->revoke();

        $this->job()->handle($this->transport);

        $this->assertSame([$active->getKey()], $this->transport->deviceIds());
    }

    public function test_no_active_device_is_recorded_as_skipped(): void
    {
        $this->job()->handle($this->transport);

        $this->assertSame(NotificationDeliveryStatus::Skipped, $this->delivery->fresh()->status);
        $this->assertSame('no_active_device', $this->delivery->fresh()->error);
        $this->assertSame([], $this->transport->calls);
    }

    public function test_an_unconfigured_transport_is_recorded_as_skipped(): void
    {
        $this->device('a');
        $this->transport->configured = false;

        $this->job()->handle($this->transport);

        $this->assertSame(NotificationDeliveryStatus::Skipped, $this->delivery->fresh()->status);
        $this->assertSame('push_not_configured', $this->delivery->fresh()->error);
    }

    public function test_a_permanently_rejected_token_is_revoked_and_not_retried(): void
    {
        $good = $this->device('a');
        $bad = $this->device('b');

        $this->transport->failures[$bad->getKey()] = PushDeliveryException::permanent('UNREGISTERED');

        $this->job()->handle($this->transport);

        $this->assertFalse($bad->fresh()->isActive());
        $this->assertTrue($good->fresh()->isActive());
        $this->assertSame(NotificationDeliveryStatus::Sent, $this->delivery->fresh()->status);
    }

    public function test_every_token_rejected_permanently_marks_the_delivery_permanently_failed(): void
    {
        $bad = $this->device('a');
        $this->transport->failures[$bad->getKey()] = PushDeliveryException::permanent('UNREGISTERED');

        $this->job()->handle($this->transport);

        $delivery = $this->delivery->fresh();
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('permanent', $delivery->failure_type);
        $this->assertFalse($bad->fresh()->isActive());
    }

    public function test_a_transient_failure_is_retryable_and_a_retry_does_not_re_alert_a_sent_device(): void
    {
        $good = $this->device('a');
        $flaky = $this->device('b');

        $this->transport->failures[$flaky->getKey()] = PushDeliveryException::temporary('timeout');

        try {
            $this->job()->handle($this->transport);
            $this->fail('A transient failure should be rethrown so the queue retries.');
        } catch (RuntimeException) {
            // Expected: the queue will retry the delivery.
        }

        $delivery = $this->delivery->fresh();
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('temporary', $delivery->failure_type);
        $this->assertSame([$good->getKey()], $delivery->provider_payload['sent_device_ids']);

        // The second attempt only talks to the device that has not succeeded.
        $this->transport->calls = [];
        unset($this->transport->failures[$flaky->getKey()]);
        $this->job()->handle($this->transport);

        $this->assertSame([$flaky->getKey()], $this->transport->deviceIds());
        $this->assertSame(NotificationDeliveryStatus::Sent, $this->delivery->fresh()->status);
    }

    public function test_a_completed_delivery_is_left_alone(): void
    {
        $this->device('a');
        $this->delivery->markSent('done');

        $this->job()->handle($this->transport);

        $this->assertSame([], $this->transport->calls);
        $this->assertSame('done', $this->delivery->fresh()->provider_message_id);
    }

    private function job(): SendNotificationPush
    {
        return new SendNotificationPush((int) $this->delivery->getKey());
    }

    private function device(string $character): NotificationDevice
    {
        $token = str_repeat($character, 60);

        return NotificationDevice::query()->create([
            'user_id' => $this->user->getKey(),
            'device_id' => 'install-'.$character,
            'platform' => 'android',
            'token' => $token,
            'token_hash' => NotificationDevice::hashToken($token),
            'last_seen_at' => now(),
        ]);
    }
}

/**
 * Records what would have been sent to Firebase.
 */
final class RecordingPushTransport implements PushTransport
{
    public bool $configured = true;

    /**
     * @var list<array{device_id: int, title: string, body: string, data: array<string, string>, category: string, priority: NotificationPriority}>
     */
    public array $calls = [];

    /**
     * @var array<int, PushDeliveryException>
     */
    public array $failures = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(
        NotificationDevice $device,
        string $title,
        string $body,
        array $data,
        string $category,
        NotificationPriority $priority,
    ): string {
        $this->calls[] = [
            'device_id' => (int) $device->getKey(),
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'category' => $category,
            'priority' => $priority,
        ];

        if (isset($this->failures[(int) $device->getKey()])) {
            throw $this->failures[(int) $device->getKey()];
        }

        return 'projects/dukavo/messages/'.$device->getKey();
    }

    /**
     * @return list<int>
     */
    public function deviceIds(): array
    {
        return array_column($this->calls, 'device_id');
    }
}
