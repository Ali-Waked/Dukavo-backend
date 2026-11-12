<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelHealth;
use App\Models\ChannelConnection;
use App\Models\ChannelWebhookEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Store;
use App\Models\Tenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The generic channel webhook.
 *
 * The endpoint is anonymous, so every promise is tested from the outside: the
 * signature decides whether a delivery is accepted, the connection decides
 * whose data it is, and a redelivery never creates a second message.
 */
class ChannelWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private ChannelConnection $connection;

    private string $secret = 'telegram-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->connection = $this->withinTenant($this->tenant, fn (): ChannelConnection => ChannelConnection::query()->create([
            'store_id' => $this->store->getKey(),
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'name' => 'Support bot',
            'external_account_id' => null,
            'credentials' => [
                'bot_token' => '123:abc',
                'webhook_secret' => $this->secret,
            ],
            'status' => ChannelConnectionStatus::Connected,
            'health' => ChannelHealth::Healthy,
            'meta' => ['webhook_secret_hash' => hash('sha256', $this->secret)],
        ]));
    }

    public function test_a_signed_delivery_creates_the_customer_conversation_and_message(): void
    {
        $this->deliver($this->payload(55, 'Hello from Telegram'))->assertOk();

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('channel', 'telegram')
            ->where('channel_account', '777')
            ->first();

        $this->assertNotNull($conversation);

        $message = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversation->getKey())
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('Hello from Telegram', $message->body);

        $identity = CustomerIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('channel', 'telegram')
            ->where('external_id', '777')
            ->first();

        $this->assertNotNull($identity);
        $this->assertSame($conversation->customer_id, $identity->customer_id);
    }

    public function test_the_tenant_is_derived_from_the_connection_not_the_payload(): void
    {
        $other = $this->tenantWithStore();

        $payload = $this->payload(56, 'Trying to cross tenants');
        $payload['tenant_id'] = $other->getKey();
        $payload['message']['tenant_id'] = $other->getKey();

        $this->deliver($payload)->assertOk();

        $customer = Customer::query()
            ->withoutGlobalScopes()
            ->where('phone', null)
            ->where('name', 'Nadia Haddad')
            ->first();

        $this->assertNotNull($customer);
        $this->assertSame($this->tenant->getKey(), $customer->tenant_id);
        $this->assertSame(0, Customer::query()->withoutGlobalScopes()->where('tenant_id', $other->getKey())->count());
    }

    public function test_a_redelivery_is_acknowledged_without_creating_a_second_message(): void
    {
        $payload = $this->payload(57, 'Only once');

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk();

        $this->assertSame(
            1,
            ConversationMessage::query()->withoutGlobalScopes()
                ->where('body', 'Only once')
                ->count(),
        );
        $this->assertSame(
            1,
            ChannelWebhookEvent::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->tenant->getKey())
                ->where('provider', 'telegram')
                ->where('event_key', 'message:telegram:777:57')
                ->count(),
        );
    }

    public function test_an_invalid_signature_is_refused_before_anything_is_written(): void
    {
        $this->postJson(
            $this->url(),
            $this->payload(58, 'Forged'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'not-the-secret'],
        )->assertUnauthorized();

        $this->assertSame(
            0,
            ConversationMessage::query()->withoutGlobalScopes()->where('body', 'Forged')->count(),
        );
    }

    public function test_a_connection_from_another_tenant_cannot_be_reached_through_the_path(): void
    {
        $other = $this->tenantWithStore();

        $theirs = $this->withinTenant($other, fn (): ChannelConnection => ChannelConnection::query()->create([
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'name' => 'Their bot',
            'credentials' => ['bot_token' => '999:theirs', 'webhook_secret' => 'their-secret'],
            'status' => ChannelConnectionStatus::Connected,
            'health' => ChannelHealth::Healthy,
        ]));

        // Their connection id, our secret: the signature belongs to the
        // connection the id names, so our secret must not unlock it.
        $this->postJson(
            '/api/v1/webhooks/channels/telegram/'.$theirs->getKey(),
            $this->payload(59, 'Cross tenant'),
            ['X-Telegram-Bot-Api-Secret-Token' => $this->secret],
        )->assertUnauthorized();

        $this->assertSame(
            0,
            ConversationMessage::query()->withoutGlobalScopes()->where('body', 'Cross tenant')->count(),
        );
    }

    public function test_the_meta_handshake_accepts_only_the_configured_token(): void
    {
        config()->set('services.meta.webhook_verify_token', 'platform-verify');

        $this->get('/api/v1/webhooks/channels/instagram?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'platform-verify',
            'hub.challenge' => 'challenge-42',
        ]))->assertOk()->assertContent('challenge-42');

        $this->get('/api/v1/webhooks/channels/instagram?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong',
            'hub.challenge' => 'challenge-42',
        ]))->assertForbidden();
    }

    public function test_the_handshake_is_only_available_for_meta_channels(): void
    {
        $this->get('/api/v1/webhooks/channels/telegram?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'anything',
            'hub.challenge' => 'x',
        ]))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload): TestResponse
    {
        return $this->postJson($this->url(), $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ]);
    }

    private function url(): string
    {
        return '/api/v1/webhooks/channels/telegram/'.$this->connection->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $messageId, string $text): array
    {
        return [
            'update_id' => $messageId,
            'message' => [
                'message_id' => $messageId,
                'date' => now()->timestamp,
                'chat' => ['id' => 777, 'type' => 'private'],
                'from' => [
                    'id' => 777,
                    'first_name' => 'Nadia',
                    'last_name' => 'Haddad',
                    'username' => 'nadia',
                ],
                'text' => $text,
            ],
        ];
    }
}
