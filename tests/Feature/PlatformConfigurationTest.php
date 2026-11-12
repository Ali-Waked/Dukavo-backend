<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IntegrationHealthState;
use App\Enums\NotificationChannel;
use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Configuration\FeatureFlagService;
use App\Services\Notifications\ChannelAvailability;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Centralised platform configuration.
 *
 * The settings surface is only safe if a few invariants hold together, so this
 * file pins them as one story: the registry is a closed allow-list, a database
 * override wins over the environment and is applied to the running process,
 * secrets are encrypted, masked and audited without their plaintext, section
 * permissions are enforced server side, feature flags really withdraw a
 * capability, and maintenance mode never locks an operator out.
 */
class PlatformConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_settings_catalogue_is_available_to_a_platform_operator(): void
    {
        $response = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/settings')
            ->assertOk()
            ->assertJsonPath('data.permissions.update', true)
            ->assertJsonPath('data.permissions.secrets', true);

        $categories = collect($response->json('data.categories'))->pluck('category')->all();

        $this->assertContains('general', $categories);
        $this->assertContains('security', $categories);
        $this->assertContains('features', $categories);
        $this->assertContains('infrastructure', $categories);
    }

    public function test_a_tenant_merchant_cannot_read_or_write_platform_settings(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/settings')
            ->assertStatus(403);

        $this->actingAs($merchant, 'sanctum')
            ->putJson('/api/v1/platform/settings/general', ['values' => ['platform_name' => 'Hijacked']])
            ->assertStatus(403);
    }

    public function test_a_general_setting_is_stored_and_applied_to_the_running_process(): void
    {
        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/general', ['values' => [
                'platform_name' => 'Acme Commerce',
                'support_email' => 'help@acme.test',
            ]])
            ->assertOk()
            ->assertJsonPath('data.settings.0.source', 'database');

        $this->assertSame('Acme Commerce', config('dukavo.platform.name'));
        $this->assertSame('Acme Commerce', config('dukavo.name'));
        $this->assertSame('help@acme.test', config('dukavo.platform.support_email'));

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'general.platform_name',
            'value' => 'Acme Commerce',
        ]);
    }

    public function test_clearing_a_setting_falls_back_to_the_environment_or_default(): void
    {
        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/platform/settings/general', ['values' => ['platform_name' => 'Temporary']])
            ->assertOk();

        $this->assertDatabaseHas('platform_settings', ['key' => 'general.platform_name']);

        $response = $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/platform/settings/general', ['values' => ['platform_name' => null]])
            ->assertOk();

        $this->assertDatabaseMissing('platform_settings', ['key' => 'general.platform_name']);
        $this->assertNotSame('database', $response->json('data.settings.0.source'));
        $this->assertNotSame('Temporary', config('dukavo.platform.name'));
    }

    public function test_a_secret_is_encrypted_masked_and_audited_without_plaintext(): void
    {
        $plaintext = 's3-secret-value-9876';

        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/storage', ['values' => ['s3_secret' => $plaintext]])
            ->assertOk();

        $row = PlatformSetting::query()->where('key', 'storage.s3_secret')->sole();

        $this->assertTrue($row->is_secret);
        $this->assertNotSame($plaintext, $row->value);
        $this->assertSame($plaintext, Crypt::decryptString($row->value));
        $this->assertSame('9876', $row->secret_hint);

        $show = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/settings/storage')
            ->assertOk();

        $secret = collect($show->json('data.category.settings'))->firstWhere('key', 'storage.s3_secret');

        $this->assertNull($secret['value']);
        $this->assertTrue($secret['configured']);
        $this->assertSame('9876', $secret['secret_hint']);
        $this->assertStringNotContainsString($plaintext, $show->getContent());

        $history = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/settings/history')
            ->assertOk();

        $this->assertStringNotContainsString($plaintext, $history->getContent());
        $this->assertSame('9876', $history->json('data.changes.0.new_values.secret_hint'));

        $catalogue = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/settings')
            ->assertOk();

        $this->assertStringNotContainsString($plaintext, $catalogue->getContent());
    }

    public function test_a_whatsapp_secret_saved_in_settings_verifies_inbound_webhooks(): void
    {
        $plaintext = 'meta-app-secret-4321';

        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/whatsapp', ['values' => ['app_secret' => $plaintext]])
            ->assertOk();

        $this->assertSame($plaintext, config('services.whatsapp.app_secret'));

        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

        $this->app['auth']->forgetGuards();

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $plaintext),
            ],
            $body,
        )->assertOk();

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-wrong-secret'),
            ],
            $body,
        )->assertStatus(401);
    }

    public function test_an_operator_without_the_secret_permission_cannot_replace_one(): void
    {
        $admin = $this->platformUser('dukavo-admin');

        $this->assertFalse($admin->hasPermissionTo('platform_settings.secrets'));

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/settings/storage', ['values' => ['s3_secret' => 'will-not-save']])
            ->assertStatus(422);

        $this->assertDatabaseMissing('platform_settings', ['key' => 'storage.s3_secret']);
    }

    public function test_unknown_keys_are_rejected_rather_than_silently_ignored(): void
    {
        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/general', ['values' => ['not_a_setting' => 'x']])
            ->assertStatus(422);
    }

    public function test_a_view_only_operator_can_read_but_not_write_a_section(): void
    {
        $viewer = $this->operatorWith(['platform_settings.view']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/platform/settings/general')
            ->assertOk();

        $this->actingAs($viewer, 'sanctum')
            ->putJson('/api/v1/platform/settings/general', ['values' => ['platform_name' => 'Nope']])
            ->assertStatus(403);
    }

    public function test_security_settings_need_the_security_manage_permission(): void
    {
        $operator = $this->operatorWith([
            'platform_settings.view',
            'platform_settings.update',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/settings/security')
            ->assertStatus(403);

        $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/platform/settings/security', ['values' => ['password_min_length' => 20]])
            ->assertStatus(403);

        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/security', ['values' => [
                'password_min_length' => 20,
                'login_rate_limit_per_minute' => 7,
            ]])
            ->assertOk();

        $this->assertSame(20, config('dukavo.security.password.min_length'));
        $this->assertSame(7, config('dukavo.security.login_rate_limit_per_minute'));
    }

    public function test_infrastructure_is_visible_but_read_only(): void
    {
        $operator = $this->platformUser('super-admin');

        $show = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/settings/infrastructure')
            ->assertOk();

        $appKey = collect($show->json('data.category.settings'))->firstWhere('key', 'infrastructure.app_key');

        $this->assertNotNull($appKey);
        $this->assertFalse($appKey['editable']);
        $this->assertNull($appKey['value']);
        $this->assertNotSame('database', $appKey['source']);

        $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/platform/settings/infrastructure', ['values' => ['app_env' => 'production']])
            ->assertStatus(422);
    }

    public function test_a_feature_flag_toggle_withdraws_the_capability_at_runtime(): void
    {
        $operator = $this->platformUser('super-admin');

        $response = $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/platform/settings/features/email_notifications', ['enabled' => false])
            ->assertOk();

        $flag = collect($response->json('data.features'))->firstWhere('key', 'email_notifications');

        $this->assertFalse($flag['enabled']);
        $this->assertFalse(config('dukavo.features.email_notifications'));
        $this->assertFalse($this->app->make(FeatureFlagService::class)->enabled('email_notifications'));
        $this->assertSame(
            'email_notifications_feature_disabled',
            $this->app->make(ChannelAvailability::class)->reason(NotificationChannel::Mail),
        );
    }

    public function test_an_unknown_feature_flag_is_a_404(): void
    {
        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/features/not_a_flag', ['enabled' => true])
            ->assertStatus(404);
    }

    public function test_maintenance_mode_answers_503_without_locking_platform_operators_out(): void
    {
        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->putJson('/api/v1/platform/settings/maintenance', ['values' => [
                'enabled' => true,
                'message' => 'Scheduled maintenance.',
                'retry_after_seconds' => 120,
            ]])
            ->assertOk();

        $this->assertTrue(config('dukavo.maintenance.enabled'));

        // A signed-in merchant is refused before tenant resolution runs.
        $tenant = $this->tenantWithStore();
        $merchant = $this->memberOf($tenant, 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/dashboard')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '120')
            ->assertJsonPath('message', 'Scheduled maintenance.');

        // Authentication stays reachable so an operator can still sign in.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422);

        // A platform operator continues to work and can turn it back off.
        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/settings/system')
            ->assertOk()
            ->assertJsonPath('data.maintenance.enabled', true);
    }

    public function test_reading_health_does_not_probe_and_an_explicit_check_is_recorded(): void
    {
        Http::fake();

        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/settings/health')
            ->assertOk()
            ->assertJsonPath('data.integrations.0.state', 'not_tested');

        Http::assertNothingSent();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/platform/settings/health/ai')
            ->assertOk()
            ->assertJsonPath('data.integration.state', IntegrationHealthState::ConfigurationRequired->value);

        $this->assertDatabaseHas('integration_health_checks', [
            'integration' => 'ai',
            'state' => IntegrationHealthState::ConfigurationRequired->value,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/platform/settings/health/not-an-integration')
            ->assertStatus(404);
    }

    /**
     * A platform operator holding exactly the given permissions.
     *
     * @param  list<string>  $permissions
     */
    private function operatorWith(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Settings Tester',
            'slug' => 'settings-tester',
            'scope' => RoleScope::Platform,
            'description' => 'Limited platform operator used by the settings tests.',
            'is_system' => false,
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id'),
        );

        $user = User::factory()->create();

        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => null,
        ]);

        return $user->fresh();
    }
}
