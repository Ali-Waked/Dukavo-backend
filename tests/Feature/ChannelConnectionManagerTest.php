<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelHealth;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ChannelConnection;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\ChannelConnectionManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The channel connection lifecycle.
 *
 * These tests pin the promises that make the integration centre safe: only
 * declared credential fields are stored, the blob is encrypted at rest, a
 * failed provider check is recorded rather than thrown, and a channel awaiting
 * provider access is never presented as connected.
 */
class ChannelConnectionManagerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    private ChannelConnectionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');

        $this->manager = app(ChannelConnectionManager::class);
    }

    public function test_a_telegram_bot_is_connected_with_encrypted_credentials_and_a_hashed_secret(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['username' => 'dukavo_bot'],
            ]),
        ]);

        $connection = $this->withinTenant($this->tenant, fn (): ChannelConnection => $this->manager->connect([
            'channel' => 'telegram',
            'store_id' => $this->store->getKey(),
            'name' => 'Support bot',
            'credentials' => ['bot_token' => '123456:secret-bot-token'],
        ]));

        $this->assertSame(ChannelConnectionStatus::Connected, $connection->status);
        $this->assertSame(ChannelHealth::Healthy, $connection->health);
        $this->assertSame('@dukavo_bot', $connection->display_name);
        $this->assertSame('123456:secret-bot-token', $connection->credentials['bot_token']);
        $this->assertNotEmpty($connection->credentials['webhook_secret']);

        // The raw column must never contain the token: the model cast encrypts it.
        $raw = DB::table('channel_connections')->where('id', $connection->getKey())->value('credentials');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-bot-token', $raw);

        // Only a hash of the webhook secret is stored outside the encrypted blob.
        $this->assertSame(
            hash('sha256', (string) $connection->credentials['webhook_secret']),
            $connection->meta['webhook_secret_hash'],
        );
        $this->assertArrayNotHasKey('webhook_secret', $connection->meta);
    }

    public function test_an_unknown_credential_field_is_rejected(): void
    {
        $this->expectException(BusinessRuleViolation::class);
        $this->expectExceptionMessage('not valid for telegram');

        $this->withinTenant($this->tenant, fn () => $this->manager->connect([
            'channel' => 'telegram',
            'credentials' => [
                'bot_token' => '123:abc',
                'tenant_id' => '99',
            ],
        ]));
    }

    public function test_required_credentials_must_be_present(): void
    {
        $this->expectException(BusinessRuleViolation::class);
        $this->expectExceptionMessage('Required credentials are missing');

        $this->withinTenant($this->tenant, fn () => $this->manager->connect([
            'channel' => 'telegram',
            'credentials' => [],
        ]));
    }

    public function test_a_failed_provider_check_is_recorded_as_an_error_not_thrown(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $connection = $this->withinTenant($this->tenant, fn (): ChannelConnection => $this->manager->connect([
            'channel' => 'telegram',
            'credentials' => ['bot_token' => 'bad-token'],
        ]));

        $this->assertSame(ChannelConnectionStatus::Error, $connection->status);
        $this->assertSame(ChannelHealth::Failing, $connection->health);
        $this->assertNotNull($connection->error_message);
        $this->assertNotNull($connection->last_health_check_at);
    }

    public function test_a_channel_awaiting_provider_access_is_never_marked_connected(): void
    {
        $connection = $this->withinTenant($this->tenant, fn (): ChannelConnection => $this->manager->connect([
            'channel' => 'tiktok',
            'credentials' => [
                'client_key' => 'tt-client-key',
                'client_secret' => 'tt-client-secret',
            ],
        ]));

        $this->assertSame(ChannelConnectionStatus::Pending, $connection->status);
        $this->assertSame(ChannelHealth::Failing, $connection->health);
        $this->assertStringContainsString('awaiting provider approval', (string) $connection->error_message);
    }

    public function test_disconnecting_erases_the_credentials_but_keeps_the_row(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'dukavo_bot']]),
        ]);

        $connection = $this->withinTenant($this->tenant, fn (): ChannelConnection => $this->manager->connect([
            'channel' => 'telegram',
            'credentials' => ['bot_token' => '123:abc'],
        ]));

        $this->withinTenant($this->tenant, fn () => $this->manager->disconnect($connection));

        $connection->refresh();

        $this->assertSame(ChannelConnectionStatus::Disconnected, $connection->status);
        $this->assertNull($connection->credentials);
        $this->assertDatabaseHas('channel_connections', ['id' => $connection->getKey()]);
    }

    public function test_the_api_lists_channels_without_ever_returning_a_credential_value(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'dukavo_bot']]),
        ]);

        $this->withinTenant($this->tenant, fn (): ChannelConnection => $this->manager->connect([
            'channel' => 'telegram',
            'name' => 'Support bot',
            'credentials' => ['bot_token' => '123456:do-not-leak-me'],
        ]));

        $response = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/integrations')
            ->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('do-not-leak-me', $body);

        $channels = $response->json('data.channels');
        $this->assertIsArray($channels);
        $this->assertCount(8, $channels);

        $telegram = collect($channels)->firstWhere('key', 'telegram');
        $this->assertNotNull($telegram);
        $this->assertSame(1, $telegram['connected_count']);

        $fields = collect($telegram['connections'][0]['credential_fields'])->keyBy('key');
        $this->assertTrue($fields['bot_token']['configured']);
        $this->assertArrayNotHasKey('value', $fields['bot_token']);
    }

    public function test_another_tenants_connection_never_appears_in_the_catalogue(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'other_bot']]),
        ]);

        $other = $this->tenantWithStore();

        $theirs = $this->withinTenant($other, fn (): ChannelConnection => $this->manager->connect([
            'channel' => 'telegram',
            'name' => 'Their bot',
            'credentials' => ['bot_token' => '999:theirs'],
        ]));

        $response = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/integrations')
            ->assertOk();

        $this->assertStringNotContainsString('Their bot', (string) $response->getContent());
        $this->assertNotContains(
            $theirs->getKey(),
            collect($response->json('data.channels'))->pluck('connections')->flatten(1)->pluck('id')->all(),
        );
    }
}
