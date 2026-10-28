<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The notification centre API.
 *
 * These tests are mostly about the boundary: a user sees, counts, reads and
 * dismisses their own notifications and nothing else. An id belonging to another
 * account must look like it does not exist (404), not like it is forbidden.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
    }

    public function test_the_index_returns_only_the_callers_notifications(): void
    {
        $mine = $this->notify($this->user);
        $this->notify($this->other);

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mine->getKey())
            ->assertJsonCount(1, 'data');
    }

    public function test_the_unread_count_reflects_unread_notifications(): void
    {
        $this->notify($this->user);
        $this->notify($this->user)->markAsRead();
        $this->notify($this->other);

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_a_notification_can_be_marked_read(): void
    {
        $notification = $this->notify($this->user);

        $this->actingAs($this->user)
            ->postJson("/api/v1/notifications/{$notification->getKey()}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_all_notifications_can_be_marked_read(): void
    {
        $this->notify($this->user);
        $this->notify($this->user);

        $this->actingAs($this->user)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(0, $this->user->notifications()->whereNull('read_at')->count());
    }

    public function test_a_notification_can_be_dismissed(): void
    {
        $notification = $this->notify($this->user);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/notifications/{$notification->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->getKey()]);
    }

    public function test_the_index_filters_by_category_severity_and_read_state(): void
    {
        $match = $this->notify($this->user, [
            'category' => 'orders',
            'severity' => 'warning',
        ]);
        $this->notify($this->user, [
            'category' => 'orders',
            'severity' => 'warning',
            'read_at' => now(),
        ]);
        $this->notify($this->user, [
            'category' => 'messages',
            'severity' => 'warning',
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?category=orders&severity=warning&status=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->getKey());

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?severity=severe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('severity');
    }

    public function test_a_read_notification_can_be_marked_unread_again(): void
    {
        $notification = $this->notify($this->user, ['read_at' => now()]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/notifications/{$notification->getKey()}/unread")
            ->assertOk()
            ->assertJsonPath('data.read', false);

        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_another_users_notification_is_hidden(): void
    {
        $notification = $this->notify($this->other);

        $this->actingAs($this->user)
            ->postJson("/api/v1/notifications/{$notification->getKey()}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function notify(User $user, array $attributes = []): Notification
    {
        return Notification::factory()->forUser($user)->create($attributes);
    }
}
