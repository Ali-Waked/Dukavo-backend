<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SecurityEventStatus;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\SecurityEventLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The account's own, append-only security history.
 *
 * The owner can see what happened and where, but never a credential. Every
 * query is scoped to the caller, so the history cannot become a side channel
 * for another account's activity.
 */
class SecurityActivityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->user = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_a_successful_login_is_recorded_with_device_and_location(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders([
                'CF-IPCountry' => 'AE',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
            ])
            ->postJson('/api/v1/auth/login', [
                'email' => $this->user->email,
                'password' => 'password',
            ])
            ->assertOk();

        $event = SecurityEvent::query()
            ->where('user_id', $this->user->getKey())
            ->where('event', SecurityEventType::LoginSuccessful)
            ->firstOrFail();

        $this->assertSame(SecurityEventStatus::Success, $event->status);
        $this->assertSame('AE', $event->country_code);
        $this->assertSame('Chrome on Windows', $event->device);
        $this->assertSame('8.8.8.8', $event->ip_address);
        $this->assertSame('password', $event->metadata['method']);
        $this->assertArrayNotHasKey('password', $event->metadata);
    }

    public function test_a_failed_login_against_a_known_account_is_recorded(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'definitely-wrong',
        ])->assertUnprocessable();

        $event = SecurityEvent::query()
            ->where('user_id', $this->user->getKey())
            ->where('event', SecurityEventType::LoginFailed)
            ->firstOrFail();

        $this->assertSame(SecurityEventStatus::Failure, $event->status);
        $this->assertSame($this->user->email, $event->metadata['email']);
        $this->assertArrayNotHasKey('password', $event->metadata);
    }

    public function test_a_failed_login_for_an_unknown_address_is_not_attached_to_anyone(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'definitely-wrong',
        ])->assertUnprocessable();

        $this->assertSame(0, SecurityEvent::query()->where('event', SecurityEventType::LoginFailed)->count());
    }

    public function test_the_history_is_returned_newest_first_with_a_readable_shape(): void
    {
        $logger = app(SecurityEventLogger::class);

        $logger->record($this->user, SecurityEventType::PasswordChanged, SecurityEventStatus::Success, [], null);
        $older = $logger->record($this->user, SecurityEventType::LoginSuccessful, SecurityEventStatus::Success);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/security/activity')
            ->assertOk()
            ->assertJsonPath('data.0.event', SecurityEventType::PasswordChanged->value)
            ->assertJsonPath('data.0.label', 'Password changed')
            ->assertJsonPath('data.0.category', 'security')
            ->assertJsonPath('data.0.status', 'success')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'event', 'label', 'category', 'status', 'status_label',
                    'ip_address', 'device', 'country_code', 'metadata', 'created_at',
                ]],
            ]);

        $this->assertSame(SecurityEventType::LoginSuccessful->value, $response->json('data.1.event'));
    }

    public function test_the_history_can_be_filtered_by_event_and_status(): void
    {
        $logger = app(SecurityEventLogger::class);
        $logger->record($this->user, SecurityEventType::PasswordChanged);
        $logger->record($this->user, SecurityEventType::LoginFailed, SecurityEventStatus::Failure);
        $logger->record($this->user, SecurityEventType::LoginSuccessful);

        $failed = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/security/activity?event=login_failed&status=failure')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $failed);
        $this->assertSame('login_failed', $failed[0]['event']);
        $this->assertSame('failure', $failed[0]['status']);
    }

    public function test_an_unknown_filter_is_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/security/activity?event=made_up')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');
    }

    public function test_one_account_can_never_see_another_accounts_history(): void
    {
        $stranger = $this->memberOf($this->tenant, 'staff');

        app(SecurityEventLogger::class)->record($stranger, SecurityEventType::PasswordChanged);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/security/activity')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_logger_strips_credentials_at_every_depth(): void
    {
        $event = app(SecurityEventLogger::class)->record($this->user, SecurityEventType::PreferencesUpdated, metadata: [
            'password' => 'super-secret',
            'current_password' => 'old-secret',
            'token' => 'bearer-token',
            'challenge_token' => 'challenge',
            'secret' => 'totp-secret',
            'recovery_codes' => ['AAAAA-11111'],
            'code' => '123456',
            'safe' => 'kept',
            'nested' => [
                'recovery_code' => 'BBBBB-22222',
                'device' => 'Chrome on Linux',
            ],
        ]);

        $metadata = $event->fresh()->metadata;

        $this->assertSame('kept', $metadata['safe']);
        $this->assertSame('Chrome on Linux', $metadata['nested']['device']);

        foreach (['password', 'current_password', 'token', 'challenge_token', 'secret', 'recovery_codes', 'code'] as $key) {
            $this->assertArrayNotHasKey($key, $metadata);
        }

        $this->assertArrayNotHasKey('recovery_code', $metadata['nested']);
        $this->assertStringNotContainsString('super-secret', json_encode($metadata));
        $this->assertStringNotContainsString('BBBBB-22222', json_encode($metadata));
    }

    public function test_old_events_are_pruned_by_the_retention_window(): void
    {
        $logger = app(SecurityEventLogger::class);

        $old = $logger->record($this->user, SecurityEventType::LoginSuccessful);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        $recent = $logger->record($this->user, SecurityEventType::PasswordChanged);

        $deleted = $logger->prune(365);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('security_events', ['id' => $old->getKey()]);
        $this->assertDatabaseHas('security_events', ['id' => $recent->getKey()]);
    }

    public function test_the_password_change_endpoint_records_the_event(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'password',
                'password' => 'N3w!Passw0rd',
                'password_confirmation' => 'N3w!Passw0rd',
            ])
            ->assertOk();

        $this->assertTrue(
            SecurityEvent::query()
                ->where('user_id', $this->user->getKey())
                ->where('event', SecurityEventType::PasswordChanged)
                ->exists(),
        );

        $this->assertNotNull($this->user->fresh()->password_changed_at);
    }
}
