<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Provider callbacks for transactional email.
 *
 * A webhook is evidence arriving after the send call, so it may only move a
 * delivery that Dukavo already recorded. The endpoint is closed when no shared
 * secret is configured, rejects an unsigned request, and maps a normalized
 * delivered/bounce/complaint payload onto the delivery ledger. An unknown
 * message id is acknowledged rather than retried forever.
 */
class EmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-email-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['dukavo.email.webhook_secret' => self::SECRET]);
    }

    public function test_the_webhook_is_closed_when_no_secret_is_configured(): void
    {
        config(['dukavo.email.webhook_secret' => null]);

        $this->postWebhook(['message_id' => 'msg-1', 'event' => 'delivered'])
            ->assertStatus(403);
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->call('POST', '/api/v1/webhooks/email', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['message_id' => 'msg-1', 'event' => 'delivered'], JSON_THROW_ON_ERROR))
            ->assertStatus(401);
    }

    public function test_a_request_with_the_wrong_signature_is_rejected_and_changes_nothing(): void
    {
        $delivery = $this->sentDelivery('msg-1');

        $this->call('POST', '/api/v1/webhooks/email', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DUKAVO_SIGNATURE' => 'sha256=not-the-right-signature',
        ], json_encode(['message_id' => 'msg-1', 'event' => 'delivered'], JSON_THROW_ON_ERROR))
            ->assertStatus(401);

        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->fresh()->status);
    }

    public function test_a_delivered_receipt_is_recorded_with_the_provider_payload(): void
    {
        $delivery = $this->sentDelivery('msg-1');

        $payload = [
            'message_id' => 'msg-1',
            'event' => 'delivered',
            'recipient' => 'layla@example.test',
            'timestamp' => '2026-11-08T09:00:00Z',
        ];

        $this->postWebhook($payload)->assertOk();

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Delivered, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame($payload, $delivery->provider_payload);
    }

    public function test_a_bounce_is_recorded_as_a_permanent_provider_failure(): void
    {
        $delivery = $this->sentDelivery('msg-2');

        $this->postWebhook([
            'provider_message_id' => 'msg-2',
            'type' => 'bounce',
            'reason' => 'Mailbox not found',
        ])->assertOk();

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('permanent', $delivery->failure_type);
        $this->assertSame('Mailbox not found', $delivery->error);
        $this->assertNotNull($delivery->failed_at);
    }

    public function test_a_failed_receipt_can_be_temporary_when_the_provider_says_so(): void
    {
        $delivery = $this->sentDelivery('msg-3');

        $this->postWebhook([
            'message_id' => 'msg-3',
            'event' => 'failed',
            'reason' => 'Deferred by the receiving server',
            'permanent' => false,
        ])->assertOk();

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('temporary', $delivery->failure_type);
        $this->assertSame('Deferred by the receiving server', $delivery->error);
    }

    public function test_an_unknown_message_id_is_acknowledged_without_creating_a_delivery(): void
    {
        $this->postWebhook([
            'message_id' => 'never-recorded',
            'event' => 'delivered',
        ])->assertOk();

        $this->assertSame(0, NotificationDelivery::query()->where('provider_message_id', 'never-recorded')->count());
    }

    private function sentDelivery(string $providerMessageId): NotificationDelivery
    {
        $user = User::factory()->create();

        $notification = Notification::factory()->forUser($user)->create();

        return NotificationDelivery::factory()->create([
            'notification_id' => $notification->getKey(),
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'channel' => NotificationChannel::Mail,
            'status' => NotificationDeliveryStatus::Sent,
            'attempts' => 1,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/email', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DUKAVO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }
}
