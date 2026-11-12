<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelHealth;
use App\Enums\MessageType;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ChannelConnection;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\CustomerIdentityResolver;
use App\Services\Channels\Data\NormalizedInboundMessage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer identity resolution and explicit linking.
 *
 * The conservative rule is the point: a display name or username is never
 * proof of identity, so only an exact phone (WhatsApp/SMS), an exact email, or
 * a deliberate link joins two channels into one customer.
 */
class CustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $merchant;

    private CustomerIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->resolver = app(CustomerIdentityResolver::class);
    }

    public function test_an_exact_phone_number_joins_the_existing_customer(): void
    {
        $existing = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'name' => 'Nadia Haddad',
            'phone' => '+15550001111',
            'status' => 'active',
        ]));

        $connection = $this->connection('sms');

        $resolved = $this->withinTenant($this->tenant, fn (): Customer => $this->resolver->resolve(
            new NormalizedInboundMessage(
                channel: 'sms',
                externalAccountId: 'Dukavo',
                externalMessageId: 'sms-1',
                senderId: '+15550001111',
                senderName: 'Nadia',
                type: MessageType::Text,
                body: 'Hello',
            ),
            $connection,
        ));

        $this->assertSame($existing->getKey(), $resolved->getKey());
        $this->assertSame(1, Customer::query()->withoutGlobalScopes()->count());
    }

    public function test_an_exact_email_joins_the_existing_customer(): void
    {
        $existing = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'name' => 'Omar Saleh',
            'email' => 'omar@example.com',
            'status' => 'active',
        ]));

        $connection = $this->connection('email');

        $resolved = $this->withinTenant($this->tenant, fn (): Customer => $this->resolver->resolve(
            new NormalizedInboundMessage(
                channel: 'email',
                externalAccountId: 'shop@example.com',
                externalMessageId: 'email-1',
                senderId: 'omar@example.com',
                senderName: 'Omar',
                type: MessageType::Text,
                body: 'Hello',
            ),
            $connection,
        ));

        $this->assertSame($existing->getKey(), $resolved->getKey());
    }

    public function test_a_matching_name_or_username_never_merges_two_people(): void
    {
        $existing = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'name' => 'Nadia Haddad',
            'phone' => '+15550002222',
            'status' => 'active',
        ]));

        $connection = $this->connection('telegram');

        $resolved = $this->withinTenant($this->tenant, fn (): Customer => $this->resolver->resolve(
            new NormalizedInboundMessage(
                channel: 'telegram',
                externalAccountId: 'bot',
                externalMessageId: 'tg-1',
                senderId: '777',
                senderName: 'Nadia Haddad',
                type: MessageType::Text,
                body: 'Hi',
                meta: ['username' => 'nadia'],
            ),
            $connection,
        ));

        $this->assertNotSame($existing->getKey(), $resolved->getKey());
        $this->assertSame(2, Customer::query()->withoutGlobalScopes()->count());
    }

    public function test_a_second_identity_for_the_same_provider_sender_is_idempotent(): void
    {
        $connection = $this->connection('telegram');

        $message = new NormalizedInboundMessage(
            channel: 'telegram',
            externalAccountId: 'bot',
            externalMessageId: 'tg-2',
            senderId: '888',
            senderName: 'Sami',
            type: MessageType::Text,
            body: 'First',
        );

        $first = $this->withinTenant($this->tenant, fn (): Customer => $this->resolver->resolve($message, $connection));
        $second = $this->withinTenant($this->tenant, fn (): Customer => $this->resolver->resolve($message, $connection));

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, CustomerIdentity::query()->withoutGlobalScopes()->count());
    }

    public function test_an_identity_cannot_be_silently_moved_to_another_customer(): void
    {
        [$first, $second] = $this->withinTenant($this->tenant, fn (): array => [
            Customer::query()->create(['name' => 'First', 'status' => 'active']),
            Customer::query()->create(['name' => 'Second', 'status' => 'active']),
        ]);

        $this->withinTenant($this->tenant, fn () => $this->resolver->link($first, 'telegram', '999'));

        $this->expectException(BusinessRuleViolation::class);
        $this->expectExceptionMessage('already linked to another customer');

        $this->withinTenant($this->tenant, fn () => $this->resolver->link($second, 'telegram', '999'));
    }

    public function test_a_merchant_can_link_and_unlink_an_identity_through_the_api(): void
    {
        $customer = $this->withinTenant($this->tenant, fn (): Customer => Customer::query()->create([
            'name' => 'Nadia Haddad',
            'status' => 'active',
        ]));

        $created = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/customers/'.$customer->getKey().'/identities', [
                'channel' => 'telegram',
                'external_id' => 'chat-1234',
                'username' => 'nadia',
                'display_name' => 'Nadia Haddad',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('telegram', $created['channel']);
        $this->assertSame('chat-1234', $created['external_id']);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/customers/'.$customer->getKey().'/identities')
            ->assertOk()
            ->assertJsonPath('data.0.external_id', 'chat-1234');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson('/api/v1/tenant/customers/'.$customer->getKey().'/identities/'.$created['id'])
            ->assertOk();

        $this->assertSame(0, CustomerIdentity::query()->withoutGlobalScopes()->count());
    }

    public function test_another_tenants_customer_is_not_reachable(): void
    {
        $other = $this->tenantWithStore();
        $theirs = $this->withinTenant($other, fn (): Customer => Customer::query()->create([
            'name' => 'Their customer',
            'status' => 'active',
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/customers/'.$theirs->getKey().'/identities')
            ->assertNotFound();
    }

    private function connection(string $channel): ChannelConnection
    {
        return $this->withinTenant($this->tenant, fn (): ChannelConnection => ChannelConnection::query()->create([
            'channel' => $channel,
            'provider' => $channel,
            'name' => ucfirst($channel),
            'credentials' => [],
            'status' => ChannelConnectionStatus::Connected,
            'health' => ChannelHealth::Healthy,
        ]));
    }
}
