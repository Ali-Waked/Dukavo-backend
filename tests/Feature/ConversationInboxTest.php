<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Conversations\ConversationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The inbox behaviours that sit on top of the conversation history: search,
 * unread state, private notes, safe assignment, templates, retry and media.
 */
class ConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    private User $staff;

    private WhatsappAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->staff = $this->memberOf($this->tenant, 'staff');

        $this->account = $this->withinTenant($this->tenant, fn (): WhatsappAccount => WhatsappAccount::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Dukavo Demo',
            'phone_number_id' => '100200300',
            'business_account_id' => '900800700',
            'access_token' => 'merchant-access-token',
            'status' => 'connected',
            'webhook_verify_token' => 'account-verify-token',
        ]));
    }

    public function test_search_matches_the_customer_and_message_history_without_leaking_tenants(): void
    {
        $nadia = $this->conversationFor('Nadia Haddad', '+15550000001');
        $omar = $this->conversationFor('Omar Saleh', '+15550000002');

        $this->message($nadia, MessageDirection::Inbound, [
            'external_id' => 'wamid.BLUE',
            'body' => 'Do you have the blue jacket?',
        ]);
        $this->message($omar, MessageDirection::Inbound, [
            'external_id' => 'wamid.REFUND',
            'body' => 'I need a refund',
        ]);

        $otherTenant = $this->tenantWithStore();
        $otherCustomer = $this->withinTenant($otherTenant, fn (): Customer => Customer::query()->create([
            'tenant_id' => $otherTenant->getKey(),
            'name' => 'Nadia Other Tenant',
            'phone' => '+15559999999',
            'status' => 'active',
        ]));
        $this->withinTenant($otherTenant, fn () => Conversation::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'customer_id' => $otherCustomer->getKey(),
            'channel' => 'whatsapp',
            'channel_account' => $otherCustomer->phone,
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.index', ['search' => 'Nadia']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nadia->getKey());

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.index', ['search' => 'refund']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $omar->getKey());

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.index', ['search' => 'blue jacket']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nadia->getKey());
    }

    public function test_unread_count_increments_once_per_inbound_message_and_mark_read_clears_it(): void
    {
        $conversation = $this->conversationFor('Dana Scott', '+15550000003');

        $this->message($conversation, MessageDirection::Inbound, ['external_id' => 'wamid.ONE', 'body' => 'One']);
        $this->message($conversation, MessageDirection::Inbound, ['external_id' => 'wamid.ONE', 'body' => 'One']);
        $this->message($conversation, MessageDirection::Inbound, ['external_id' => 'wamid.TWO', 'body' => 'Two']);

        $this->assertSame(2, $conversation->fresh()->unread_count);

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.reply_window.is_open', true);

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.read', $conversation))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.read', $conversation))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_a_note_is_internal_and_never_calls_the_provider(): void
    {
        Http::preventStrayRequests();
        $conversation = $this->conversationFor('Note Customer', '+15550000004');

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.notes', $conversation), [
                'body' => 'Customer is waiting on a stock check.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'note')
            ->assertJsonPath('data.is_internal', true)
            ->assertJsonPath('data.delivery_status', null);

        $message = ConversationMessage::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertTrue($message->isInternal());
        $this->assertNull($message->delivery_status);
        Http::assertNothingSent();
    }

    public function test_assignment_requires_a_tenant_member_and_assignees_are_readable_by_staff(): void
    {
        $conversation = $this->conversationFor('Assign Customer', '+15550000005');

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.assignees'))
            ->assertOk()
            ->assertJsonFragment(['id' => $this->staff->getKey()])
            ->assertJsonFragment(['id' => $this->merchant->getKey()]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.assign', $conversation), ['user_id' => $this->staff->getKey()])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $this->staff->getKey());

        $foreignTenant = $this->tenantWithStore();
        $foreignUser = $this->memberOf($foreignTenant, 'staff');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.assign', $conversation), ['user_id' => $foreignUser->getKey()])
            ->assertStatus(409);

        $this->assertSame($this->staff->getKey(), $conversation->fresh()->assigned_to);

        // Staff can receive work but may not hand it to someone else.
        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.assign', $conversation), ['user_id' => $this->merchant->getKey()])
            ->assertForbidden();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.assign', $conversation), ['user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', null);
    }

    public function test_free_text_is_blocked_outside_the_window_and_an_approved_template_can_be_sent(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]], 200),
        ]);

        $conversation = $this->conversationFor('Window Customer', '+15550000006');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.reply', $conversation), ['body' => 'Hello?'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '24 hours'));

        Http::assertNothingSent();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.templates', $conversation), [
                'template_name' => 'order_update',
                'language_code' => 'en_US',
                'parameters' => ['Dana', 'ORD-1'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'template')
            ->assertJsonPath('data.external_id', 'wamid.TEMPLATE')
            ->assertJsonPath('data.body', 'order_update');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->body(), 'order_update'));

        $this->message($conversation, MessageDirection::Inbound, [
            'external_id' => 'wamid.OPEN-WINDOW',
            'body' => 'I am here',
        ]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.reply', $conversation), ['body' => 'Thanks for waiting'])
            ->assertCreated();
    }

    public function test_retrying_a_failed_message_creates_a_new_linked_provider_message(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RETRY']]], 200),
        ]);

        $conversation = $this->conversationFor('Retry Customer', '+15550000007');
        $this->message($conversation, MessageDirection::Inbound, [
            'external_id' => 'wamid.RETRY-INBOUND',
            'body' => 'Where is my order?',
        ]);

        $failed = $this->message($conversation, MessageDirection::Outbound, [
            'external_id' => 'wamid.FAILED',
            'type' => MessageType::Text,
            'body' => 'Your order is on its way.',
            'delivery_status' => 'failed',
            'failure_code' => '131026',
            'failure_reason' => 'Message undeliverable',
        ]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.retry', [$conversation, $failed]))
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'wamid.RETRY')
            ->assertJsonPath('data.retry_of_id', $failed->getKey());

        $this->assertSame('failed', $failed->fresh()->delivery_status);
        $this->assertSame(2, $this->withinTenant(
            $this->tenant,
            fn () => $conversation->messages()->where('direction', 'outbound')->count(),
        ));
    }

    public function test_media_is_streamed_only_from_the_authenticated_conversation_route(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('whatsapp/1/1/receipt.png', 'png-bytes');

        $conversation = $this->conversationFor('Media Customer', '+15550000008');
        $message = $this->message($conversation, MessageDirection::Inbound, [
            'external_id' => 'wamid.MEDIA',
            'type' => MessageType::Image,
            'body' => null,
            'meta' => [
                'provider' => 'meta',
                'media' => [
                    'path' => 'whatsapp/1/1/receipt.png',
                    'mime_type' => 'image/png',
                    'size' => 9,
                    'filename' => 'receipt.png',
                ],
            ],
        ]);

        $url = route('api.tenant.conversations.media', [$conversation, $message]);

        $this->getJson($url)->assertUnauthorized();

        $response = $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame('png-bytes', $response->streamedContent());

        $other = $this->conversationFor('Other Media Customer', '+15550000009');

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get(route('api.tenant.conversations.media', [$other, $message]))
            ->assertNotFound();
    }

    public function test_conversation_events_record_team_actions_without_leaking_tenants(): void
    {
        $conversation = $this->conversationFor('Activity Customer', '+15550000010');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.assign', $conversation), ['user_id' => $this->staff->getKey()])
            ->assertOk();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.conversations.update', $conversation), ['status' => 'pending'])
            ->assertOk();

        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.events', $conversation))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'conversation_status_changed')
            ->assertJsonPath('data.0.new_values.status', 'pending')
            ->assertJsonPath('data.0.user.id', $this->merchant->getKey())
            ->assertJsonPath('data.0.auditable_id', $conversation->getKey())
            ->assertJsonPath('data.1.action', 'conversation_assigned')
            ->assertJsonPath('data.1.new_values.assigned_to', $this->staff->getKey());

        $foreignTenant = $this->tenantWithStore();
        $foreignUser = $this->memberOf($foreignTenant, 'merchant');

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant', $foreignTenant->slug)
            ->getJson(route('api.tenant.conversations.events', $conversation))
            ->assertNotFound();
    }

    public function test_status_changes_and_thread_reads_are_authorised_and_tenant_scoped(): void
    {
        $conversation = $this->conversationFor('Scoped Customer', '+15550000011');

        // Staff may work a thread but may not move it through its lifecycle.
        $this->actingAs($this->staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.conversations.update', $conversation), ['status' => 'pending'])
            ->assertForbidden();

        $this->assertSame('open', $conversation->fresh()->status->value);

        $foreignTenant = $this->tenantWithStore();
        $foreignStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $foreignTenant->getKey())
            ->firstOrFail();
        $foreignUser = $this->memberOf($foreignTenant, 'merchant');

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant', $foreignTenant->slug)
            ->getJson(route('api.tenant.conversations.show', $conversation))
            ->assertNotFound();

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant', $foreignTenant->slug)
            ->getJson(route('api.tenant.conversations.messages', $conversation))
            ->assertNotFound();

        // A store id from another tenant is a filter, never a way to widen scope.
        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.conversations.index', ['store_id' => $foreignStore->getKey()]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function conversationFor(string $customerName, string $phone, array $overrides = []): Conversation
    {
        $customer = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => $customerName,
            'phone' => $phone,
            'status' => 'active',
        ]));

        return $this->withinTenant($this->tenant, fn (): Conversation => Conversation::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => $customer->getKey(),
            'channel' => 'whatsapp',
            'channel_account' => $phone,
            'whatsapp_account_id' => $this->account->getKey(),
        ] + $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(Conversation $conversation, MessageDirection $direction, array $attributes): ConversationMessage
    {
        return $this->withinTenant(
            $this->tenant,
            fn (): ConversationMessage => app(ConversationService::class)->addMessage($conversation, $direction, $attributes),
        );
    }
}
