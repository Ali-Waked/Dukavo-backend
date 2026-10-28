<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Channels\WhatsApp\Exceptions\WhatsAppDeliveryFailed;
use App\Services\Conversations\ConversationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The WhatsApp Cloud API integration: platform configuration, credential
 * resolution and outbound delivery.
 *
 * The webhook has its own test; this one pins what happens on the way out. Three
 * properties matter. The provider is addressed through configuration rather than
 * a version baked into the code, so a Graph API sunset does not need a release.
 * A token is a credential: it travels in a header and is refused outright when
 * the account has no right to use it. And a conversation answers from the number
 * it belongs to, which is what keeps a multi-number tenant from replying to a
 * customer from the wrong shop.
 *
 * Every provider call is faked. Nothing here reaches Meta, and the assertions
 * are about what Dukavo would have sent had it been real.
 */
class WhatsappCloudApiTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_TOKEN = 'platform-access-token';

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

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

        config()->set('services.whatsapp', [
            'cloud_api_version' => 'v26.0',
            'cloud_api_token' => self::PLATFORM_TOKEN,
            'phone_number_id' => '100200300',
            'business_account_id' => '900800700',
            'webhook_verify_token' => 'env-verify-token',
            'app_secret' => 'env-app-secret',
            'api_base_url' => 'https://graph.facebook.com',
        ]);

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

    public function test_the_gateway_addresses_the_configured_graph_version(): void
    {
        $this->fakeAcceptedMessage();
        $conversation = $this->conversationFor($this->account);

        $this->withinTenant(
            $this->tenant,
            fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello'),
        );

        // The version is configuration, not a constant: a Meta sunset is one
        // environment change away, not a deploy.
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://graph.facebook.com/v26.0/100200300/messages');
    }

    public function test_the_access_token_travels_in_a_header_and_never_in_the_body(): void
    {
        $this->fakeAcceptedMessage();
        $conversation = $this->conversationFor($this->account);

        $this->withinTenant(
            $this->tenant,
            fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello'),
        );

        Http::assertSent(function (ClientRequest $request): bool {
            $this->assertSame('Bearer merchant-access-token', $request->header('Authorization')[0] ?? null);
            $this->assertStringNotContainsString('merchant-access-token', $request->body());

            return true;
        });
    }

    public function test_a_refusal_from_meta_is_reported_without_the_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException'],
            ], 401),
        ]);

        $conversation = $this->conversationFor($this->account);

        try {
            $this->withinTenant(
                $this->tenant,
                fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello'),
            );
            $this->fail('A rejected send must raise WhatsAppDeliveryFailed.');
        } catch (WhatsAppDeliveryFailed $exception) {
            $this->assertSame('whatsapp_delivery_failed', $exception->errorCode());
            $this->assertStringNotContainsString('merchant-access-token', $exception->getMessage());
            $this->assertSame(401, $exception->context()['provider_status']);
        }
    }

    public function test_sending_is_refused_when_the_number_has_no_usable_token(): void
    {
        Http::preventStrayRequests();

        $tokenless = $this->accountWithoutItsOwnToken(phoneNumberId: '999999999');
        $conversation = $this->conversationFor($tokenless);

        try {
            $this->withinTenant(
                $this->tenant,
                fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello'),
            );
            $this->fail('An uncredentialed account must not send.');
        } catch (WhatsAppDeliveryFailed $exception) {
            $this->assertSame('whatsapp_not_connected', $exception->errorCode());
        }

        Http::assertNothingSent();
    }

    public function test_the_platform_token_sends_for_the_single_configured_number(): void
    {
        $this->fakeAcceptedMessage();

        // A tenant that has not stored its own token yet may still use the
        // number the installation itself is configured with — and only that one.
        $tokenless = $this->accountWithoutItsOwnToken(phoneNumberId: '100200300');
        $conversation = $this->conversationFor($tokenless);

        $this->withinTenant(
            $this->tenant,
            fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello'),
        );

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::PLATFORM_TOKEN));
    }

    public function test_an_account_in_another_tenant_is_never_used_to_send(): void
    {
        Http::preventStrayRequests();

        $other = $this->tenantWithStore();
        $foreignAccount = $this->withinTenant($other, fn (): WhatsappAccount => WhatsappAccount::query()->create([
            'tenant_id' => $other->getKey(),
            'name' => 'Someone else',
            'phone_number_id' => '100200300',
            'access_token' => 'foreign-access-token',
            'status' => 'connected',
            'webhook_verify_token' => 'foreign-verify-token',
        ]));

        // The conversation belongs to this tenant but points at a number this
        // tenant does not own: guessing the id must not grant the send.
        $conversation = $this->conversationFor($foreignAccount);

        try {
            $this->withinTenant(
                $this->tenant,
                fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello'),
            );
            $this->fail('A conversation may not send through another tenant\'s account.');
        } catch (WhatsAppDeliveryFailed $exception) {
            $this->assertSame('whatsapp_not_connected', $exception->errorCode());
        }

        Http::assertNothingSent();
    }

    public function test_a_reply_from_the_inbox_is_sent_and_recorded(): void
    {
        $this->fakeAcceptedMessage();
        $conversation = $this->conversationFor($this->account);

        // Free-form replies are only legal inside the 24-hour customer service
        // window, so the test starts from a real inbound message.
        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)->addMessage(
            $conversation,
            MessageDirection::Inbound,
            ['external_id' => 'wamid.INBOUND', 'type' => MessageType::Text, 'body' => 'Do you have it?'],
        ));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.conversations.reply', $conversation), ['body' => 'On its way'])
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'wamid.ACCEPTED');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->body(), 'On its way'));

        $message = $this->withinTenant($this->tenant, fn () => $conversation->messages()
            ->where('direction', MessageDirection::Outbound)
            ->firstOrFail());

        $this->assertSame('wamid.ACCEPTED', $message->external_id);
        $this->assertSame('sent', $message->delivery_status);
    }

    public function test_the_configuration_endpoint_reports_booleans_and_no_secrets(): void
    {
        $response = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.whatsapp.configuration'))
            ->assertOk()
            ->assertJsonPath('data.api_version', 'v26.0')
            ->assertJsonPath('data.configured.access_token', true)
            ->assertJsonPath('data.configured.app_secret', true)
            ->assertJsonPath('data.webhook.signature_validation', 'enforced');

        $body = (string) $response->getContent();

        foreach ([self::PLATFORM_TOKEN, 'merchant-access-token', 'env-app-secret', 'env-verify-token', 'account-verify-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, 'The configuration endpoint leaked a secret.');
        }
    }

    public function test_the_configuration_endpoint_says_when_signatures_are_not_enforced(): void
    {
        config()->set('services.whatsapp.app_secret', null);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.whatsapp.configuration'))
            ->assertOk()
            ->assertJsonPath('data.configured.app_secret', false)
            ->assertJsonPath('data.webhook.signature_validation', 'not_configured');
    }

    public function test_staff_can_read_the_platform_configuration_but_not_change_it(): void
    {
        // Reading the channel is part of working the inbox, so staff hold
        // `whatsapp.view`. Connecting a number is not, and must stay refused.
        $staff = $this->memberOf($this->tenant, 'staff');

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.whatsapp.configuration'))
            ->assertOk();

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.whatsapp.store'), [
                'name' => 'Another number',
                'phone_number_id' => '555555',
                'access_token' => 'not-allowed',
            ])
            ->assertForbidden();
    }

    public function test_the_caller_idempotency_key_is_kept_separate_from_the_provider_message_id(): void
    {
        $this->fakeAcceptedMessage();
        $conversation = $this->conversationFor($this->account);

        $first = $this->withinTenant(
            $this->tenant,
            fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello', 'client-key-123'),
        );

        // A webhook receipt carries Meta's id, so Dukavo must store that id in
        // external_id and keep its own key elsewhere.
        $message = $this->withinTenant($this->tenant, fn () => $conversation->messages()->firstOrFail());

        $this->assertSame('wamid.ACCEPTED', $first);
        $this->assertSame('wamid.ACCEPTED', $message->external_id);
        $this->assertSame('client-key-123', $message->idempotency_key);

        $replayed = $this->withinTenant(
            $this->tenant,
            fn () => app(WhatsAppGateway::class)->sendText($conversation, 'Hello', 'client-key-123'),
        );

        $this->assertSame('wamid.ACCEPTED', $replayed);
        $this->assertSame(1, $this->withinTenant($this->tenant, fn () => $conversation->messages()->count()));
        Http::assertSentCount(1);
    }

    private function fakeAcceptedMessage(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.ACCEPTED']],
            ], 200),
        ]);
    }

    /**
     * An account with no token of its own, so the platform token may apply —
     * but only when the number matches.
     */
    private function accountWithoutItsOwnToken(string $phoneNumberId): WhatsappAccount
    {
        return $this->withinTenant($this->tenant, fn (): WhatsappAccount => WhatsappAccount::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Tokenless',
            'phone_number_id' => $phoneNumberId,
            'access_token' => null,
            'status' => 'connected',
            'webhook_verify_token' => 'tokenless-verify-token',
        ]));
    }

    /**
     * An open WhatsApp thread belonging to `$account`'s tenant, bound to that
     * account exactly as the webhook binds one.
     */
    private function conversationFor(WhatsappAccount $account): Conversation
    {
        $customer = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Dana Scott',
            'phone' => '+1555'.fake()->unique()->numerify('######'),
            'status' => 'active',
        ]));

        return $this->withinTenant($this->tenant, fn (): Conversation => app(ConversationService::class)
            ->openForCustomer($customer, 'whatsapp', $customer->phone, [
                'whatsapp_account_id' => $account->getKey(),
                'store_id' => $account->store_id,
            ]));
    }
}
