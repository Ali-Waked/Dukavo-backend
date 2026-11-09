<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Push device registration.
 *
 * A token belongs to one installation and one account. These tests pin the
 * promises that keep a phone from receiving another account's alerts after a
 * sign-out, and that keep a rotated token from leaving two live rows behind.
 */
class NotificationDeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_a_device_registers_and_the_token_is_never_returned(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/devices', [
                'token' => str_repeat('a', 60),
                'device_id' => 'install-1',
                'platform' => 'android',
                'device_name' => 'Pixel',
                'app_version' => '1.0.0',
                'locale' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('notification_devices', [
            'user_id' => $this->user->getKey(),
            'device_id' => 'install-1',
            'token_hash' => NotificationDevice::hashToken(str_repeat('a', 60)),
        ]);

        $this->assertStringNotContainsString(str_repeat('a', 60), $response->getContent());
    }

    public function test_registering_the_same_token_twice_updates_one_row(): void
    {
        $payload = [
            'token' => str_repeat('b', 60),
            'device_id' => 'install-2',
            'platform' => 'ios',
        ];

        $this->actingAs($this->user)->postJson('/api/v1/profile/devices', $payload)->assertOk();
        $payload['device_name'] = 'iPhone';
        $this->actingAs($this->user)->postJson('/api/v1/profile/devices', $payload)->assertOk();

        $this->assertSame(1, $this->user->notificationDevices()->count());
        $this->assertSame('iPhone', $this->user->notificationDevices()->firstOrFail()->device_name);
    }

    public function test_a_rotated_token_revokes_the_previous_installation_row(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/profile/devices', [
            'token' => str_repeat('c', 60),
            'device_id' => 'install-3',
            'platform' => 'android',
        ])->assertOk();

        $this->actingAs($this->user)->postJson('/api/v1/profile/devices', [
            'token' => str_repeat('d', 60),
            'device_id' => 'install-3',
            'platform' => 'android',
        ])->assertOk();

        $devices = $this->user->notificationDevices()->orderBy('id')->get();

        $this->assertCount(2, $devices);
        $this->assertFalse($devices[0]->isActive());
        $this->assertTrue($devices[1]->isActive());
    }

    public function test_the_same_token_signed_into_another_account_moves_to_that_account(): void
    {
        $other = User::factory()->create();
        $token = str_repeat('e', 60);

        $this->actingAs($this->user)->postJson('/api/v1/profile/devices', [
            'token' => $token,
            'device_id' => 'shared-install',
            'platform' => 'android',
        ])->assertOk();

        $this->actingAs($other)->postJson('/api/v1/profile/devices', [
            'token' => $token,
            'device_id' => 'shared-install',
            'platform' => 'android',
        ])->assertOk();

        $this->assertSame(0, $this->user->notificationDevices()->active()->count());
        $this->assertSame(1, $other->notificationDevices()->active()->count());
    }

    public function test_logout_can_unregister_by_token_without_the_numeric_id(): void
    {
        $token = str_repeat('f', 60);

        $this->actingAs($this->user)->postJson('/api/v1/profile/devices', [
            'token' => $token,
            'device_id' => 'install-4',
            'platform' => 'android',
        ])->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/profile/devices/unregister', ['token' => $token])
            ->assertNoContent();

        $this->assertSame(0, $this->user->notificationDevices()->active()->count());
    }

    public function test_another_users_device_is_not_found(): void
    {
        $other = User::factory()->create();
        $device = NotificationDevice::query()->create([
            'user_id' => $other->getKey(),
            'device_id' => 'install-5',
            'platform' => 'android',
            'token' => str_repeat('g', 60),
            'token_hash' => NotificationDevice::hashToken(str_repeat('g', 60)),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/profile/devices/{$device->getKey()}")
            ->assertNotFound();

        $this->assertTrue($device->fresh()->isActive());
    }

    public function test_registration_validates_the_platform(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/profile/devices', [
                'token' => str_repeat('h', 60),
                'platform' => 'symbian',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform');
    }
}
