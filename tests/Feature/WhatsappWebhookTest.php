<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use App\Services\Conversations\ConversationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The inbound Meta webhook.
 *
 * This endpoint has no session and no tenant header: everything it trusts is
 * inside the payload, so it has to establish both who the caller is and which
 * tenant it speaks for. These tests pin the three promises made in the controller
 * docblock — no credential leaks, no redelivery loops, and no trust in the
 * sender's claims about whose data it is.
 */
class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private WhatsappAccount $account;

    private ConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->account = WhatsappAccount::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Dukavo Demo',
            'phone_number_id' => '100200300',
            'business_account_id' => '900800700',
            'access_token' => 'meta-token-value',
            'status' => 'connected',
            'webhook_verify_token' => 'verify-me',
        ]);

        $this->conversations = app(ConversationService::class);
    }

    public function test_the_handshake_echoes_the_challenge_for_a_known_token(): void
    {
        $this->getJson('/api/v1/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-me',
            'hub.challenge' => '1158201444',
        ]))->assertOk()->assertExactJson(['hub.challenge' => '1158201444']);
    }

    public function test_the_handshake_is_refused_for_an_unknown_token(): void
    {
        $this->getJson('/api/v1/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'not-my-token',
            'hub.challenge' => '1158201444',
        ]))->assertForbidden();
    }

    public function test_the_handshake_never_returns_the_account_credentials(): void
    {
        $response = $this->getJson('/api/v1/webhooks/whatsapp?'.http_build_query([
            'hub.verify_token' => 'verify-me',
            'hub.challenge' => '42',
        ]))->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('meta-token-value', $body);
        $this->assertStringNotContainsString('verify-me', $body);
    }

    public function test_an_inbound_message_opens_a_conversation_and_records_the_message(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230001',
                'id' => 'wamid.ABC',
                'type' => 'text',
                'text' => ['body' => 'Do you have this in blue?'],
                'profile' => ['name' => 'Dana Scott'],
            ]],
        ]))->assertOk()->assertJsonPath('success', true);

        $conversation = Conversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertSame('whatsapp', $conversation->channel);
        $this->assertSame(
            'Dana Scott',
            $this->withinTenant($this->tenant, fn () => $conversation->customer?->name),
        );

        $message = ConversationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->getKey())
            ->firstOrFail();

        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame(MessageType::Text, $message->type);
        $this->assertSame('Do you have this in blue?', $message->body);
        $this->assertSame('wamid.ABC', $message->external_id);
        $this->assertSame('meta', $message->meta['provider']);
    }

    public function test_a_redelivered_message_is_not_stored_twice(): void
    {
        $payload = $this->payload([
            'messages' => [[
                'from' => '+15551230002',
                'id' => 'wamid.REPLAY',
                'type' => 'text',
                'text' => ['body' => 'Hello again'],
            ]],
        ]);

        $this->postJson('/api/v1/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/whatsapp', $payload)->assertOk();

        $conversation = Conversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        // Meta retries on any non-2xx, so a retry must be a no-op rather than a
        // duplicated row in the customer's thread.
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->getKey())
            ->count());
    }

    public function test_two_messages_from_one_sender_share_a_conversation(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230003', 'id' => 'wamid.ONE', 'type' => 'text', 'text' => ['body' => 'One'],
            ]],
        ]))->assertOk();

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230003', 'id' => 'wamid.TWO', 'type' => 'text', 'text' => ['body' => 'Two'],
            ]],
        ]))->assertOk();

        $this->assertSame(1, Conversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->count());

        $this->assertSame(2, ConversationMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_known_customer_is_reused_rather_than_duplicated(): void
    {
        $existing = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Existing Customer',
            'phone' => '+15551230004',
            'status' => 'active',
        ]));

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230004', 'id' => 'wamid.KNOWN', 'type' => 'text', 'text' => ['body' => 'Hi'],
            ]],
        ]))->assertOk();

        $this->assertSame(1, Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->count());
        $this->assertSame($existing->getKey(), Conversation::query()->withoutGlobalScopes()
            ->firstOrFail()->customer_id);
    }

    public function test_an_image_without_a_caption_has_no_body(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230005',
                'id' => 'wamid.IMG',
                'type' => 'image',
                'image' => ['id' => 'media-1'],
            ]],
        ]))->assertOk();

        $message = ConversationMessage::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(MessageType::Image, $message->type);
        $this->assertNull($message->body);
    }

    public function test_delivery_receipts_advance_the_outbound_message(): void
    {
        $message = $this->outboundMessage('wamid.OUT');

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [['id' => 'wamid.OUT', 'status' => 'sent', 'timestamp' => '1730000000']],
        ]))->assertOk();

        // A `sent` receipt confirms nothing new, but it must not be mistaken for
        // a failure either.
        $this->assertSame('sent', $message->fresh()->delivery_status);

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [['id' => 'wamid.OUT', 'status' => 'delivered', 'timestamp' => '1730000060']],
        ]))->assertOk();

        $this->assertSame('delivered', $message->fresh()->delivery_status);

        // Read is the next step, not a downgrade of delivered.
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [['id' => 'wamid.OUT', 'status' => 'read', 'timestamp' => '1730000120']],
        ]))->assertOk();

        $read = $message->fresh();

        $this->assertSame('read', $read->delivery_status);
        $this->assertNotNull($read->delivered_at);
        $this->assertNotNull($read->read_at);
    }

    public function test_a_failure_receipt_keeps_its_reason_and_never_undoes_a_confirmation(): void
    {
        $message = $this->outboundMessage('wamid.FAIL');

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [[
                'id' => 'wamid.FAIL',
                'status' => 'failed',
                'timestamp' => '1730000000',
                'errors' => [['title' => 'Message undeliverable', 'code' => 131026]],
            ]],
        ]))->assertOk();

        $this->assertSame('failed', $message->fresh()->delivery_status);
        $this->assertStringContainsString('undeliverable', (string) ($message->fresh()->meta['error'] ?? ''));

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [['id' => 'wamid.FAIL', 'status' => 'read', 'timestamp' => '1730000200']],
        ]))->assertOk();

        $this->assertSame('read', $message->fresh()->delivery_status);

        // A late failure receipt for an already-read message must not walk the
        // delivery state backwards.
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [[
                'id' => 'wamid.FAIL',
                'status' => 'failed',
                'timestamp' => '1730000300',
                'errors' => [['title' => 'Late failure']],
            ]],
        ]))->assertOk();

        $this->assertSame('read', $message->fresh()->delivery_status);

        // The same is true of a delivered message.
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [['id' => 'wamid.FAIL', 'status' => 'delivered', 'timestamp' => '1730000400']],
        ]))->assertOk();

        $this->assertSame('read', $message->fresh()->delivery_status);
    }

    public function test_a_receipt_for_an_unknown_message_changes_nothing(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'statuses' => [['id' => 'wamid.NEVER-SENT', 'status' => 'read', 'timestamp' => '1730000000']],
        ]))->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_payload_for_an_unconnected_number_is_acknowledged_and_ignored(): void
    {
        Log::spy();

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '000000000'],
                        'messages' => [[
                            'from' => '+15559999999',
                            'id' => 'wamid.UNKNOWN',
                            'type' => 'text',
                            'text' => ['body' => 'Wrong tenant'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk()->assertJsonPath('success', true);

        // Meta must not be told to retry, and nothing may be written anywhere.
        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_payload_without_a_phone_number_id_is_acknowledged(): void
    {
        Log::spy();

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'entry' => [['changes' => [['value' => ['messages' => []]]]]],
        ])->assertOk();

        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_conversation_created_by_a_webhook_is_invisible_to_another_tenant(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230008', 'id' => 'wamid.SCOPE', 'type' => 'text', 'text' => ['body' => 'Private'],
            ]],
        ]))->assertOk();

        $other = $this->tenantWithStore();

        $this->assertCount(0, $this->withinTenant($other, fn () => Conversation::query()->get()));
        $this->assertCount(0, $this->withinTenant($other, fn () => Customer::query()->get()));
    }

    public function test_the_webhook_needs_no_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/webhooks/whatsapp', $this->payload([
            'messages' => [[
                'from' => '+15551230009', 'id' => 'wamid.ANON', 'type' => 'text', 'text' => ['body' => 'Anon'],
            ]],
        ]))->assertOk();

        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_garbled_payload_is_acknowledged_so_meta_stops_retrying(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', ['entry' => 'not-an-array-of-objects'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Wrap a change set in Meta's envelope for this tenant's account.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(array $changes): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '900800700',
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['metadata' => ['phone_number_id' => $this->account->phone_number_id]] + $changes,
                ]],
            ]],
        ];
    }

    /**
     * An outbound message waiting on receipts, as the inbox gateway would leave it.
     */
    private function outboundMessage(string $externalId): ConversationMessage
    {
        $customer = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Receipt Test',
            'phone' => '+1555'.fake()->unique()->numerify('######'),
            'status' => 'active',
        ]));

        $conversation = $this->withinTenant(
            $this->tenant,
            fn (): Conversation => $this->conversations->openForCustomer($customer, 'whatsapp', $customer->phone),
        );

        return $this->withinTenant(
            $this->tenant,
            fn (): ConversationMessage => $this->conversations->addMessage(
                $conversation,
                MessageDirection::Outbound,
                ['external_id' => $externalId, 'type' => MessageType::Text, 'body' => 'On its way'],
            ),
        );
    }
}
