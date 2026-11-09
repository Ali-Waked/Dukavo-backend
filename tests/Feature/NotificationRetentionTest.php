<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Notification and device retention.
 *
 * The store is authoritative, so it must not grow without bound — but an unread
 * operational alert is exactly what a merchant away from the shop must not lose.
 * Archived rows go first, read rows age out, unread rows stay.
 */
class NotificationRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_the_command_prunes_only_rows_past_their_window(): void
    {
        config([
            'dukavo.notifications.retention.archived_days' => 30,
            'dukavo.notifications.retention.read_days' => 180,
        ]);

        $archived = $this->notification(['archived_at' => now()->subDays(60)]);
        $oldRead = $this->notification(['read_at' => now()->subDays(200)]);
        $oldUnread = $this->notification(['created_at' => now()->subDays(200)]);
        $recentRead = $this->notification(['read_at' => now()->subDays(5)]);

        Artisan::call('notifications:prune');

        $this->assertNull(Notification::query()->find($archived->getKey()));
        $this->assertNull(Notification::query()->find($oldRead->getKey()));
        $this->assertNotNull(Notification::query()->find($oldUnread->getKey()));
        $this->assertNotNull(Notification::query()->find($recentRead->getKey()));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        config(['dukavo.notifications.retention.archived_days' => 30]);

        $archived = $this->notification(['archived_at' => now()->subDays(60)]);

        Artisan::call('notifications:prune', ['--dry-run' => true]);

        $this->assertNotNull(Notification::query()->find($archived->getKey()));
    }

    public function test_revoked_devices_are_deleted_after_the_grace_period_and_stale_ones_are_revoked(): void
    {
        config(['dukavo.notifications.retention.device_days' => 180]);

        $revoked = $this->device('a', ['revoked_at' => now()->subDays(200)]);
        $stale = $this->device('b', ['last_seen_at' => now()->subDays(200)]);
        $recent = $this->device('c', ['last_seen_at' => now()->subDay()]);

        Artisan::call('notifications:prune');

        $this->assertNull(NotificationDevice::query()->find($revoked->getKey()));

        $this->assertNotNull(NotificationDevice::query()->find($stale->getKey()));
        $this->assertNotNull($stale->fresh()->revoked_at);

        $this->assertTrue($recent->fresh()->isActive());
        $this->assertNull($recent->fresh()->revoked_at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function notification(array $attributes = []): Notification
    {
        return Notification::factory()->forUser($this->user)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function device(string $character, array $attributes = []): NotificationDevice
    {
        $token = str_repeat($character, 60);

        return NotificationDevice::query()->create([
            'user_id' => $this->user->getKey(),
            'device_id' => 'install-'.$character,
            'platform' => 'android',
            'token' => $token,
            'token_hash' => NotificationDevice::hashToken($token),
            'last_seen_at' => now(),
            ...$attributes,
        ]);
    }
}
