<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Notifications\NotificationCreated;
use App\Events\Notifications\NotificationStateChanged;
use App\Models\Notification;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Private real-time channels and the payloads that travel on them.
 *
 * The browser may only subscribe to its own user channel, a workspace it
 * belongs to, or the platform operators' channel. The event payload is an
 * accelerator for the authoritative list, not a second source of truth, so it
 * carries no customer addresses or tokens.
 */
class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
    }

    public function test_a_user_may_only_subscribe_to_their_own_personal_channel(): void
    {
        $user = $this->memberOf($this->tenant);
        $other = $this->memberOf($this->tenant);

        $callback = $this->channel('App.Models.User.{userId}');

        $this->assertTrue($callback($user, $user->getKey()));
        $this->assertFalse($callback($user, $other->getKey()));
    }

    public function test_a_tenant_channel_requires_membership(): void
    {
        $member = $this->memberOf($this->tenant);
        $outsider = $this->memberOf($this->tenantWithStore());

        $callback = $this->channel('tenant.{tenantId}');

        $this->assertTrue($callback($member, $this->tenant->getKey()));
        $this->assertFalse($callback($outsider, $this->tenant->getKey()));
    }

    public function test_a_store_channel_requires_membership_of_its_tenant(): void
    {
        $member = $this->memberOf($this->tenant);
        $outsider = $this->memberOf($this->tenantWithStore());

        $callback = $this->channel('store.{storeId}');

        $this->assertTrue($callback($member, $this->store->getKey()));
        $this->assertFalse($callback($outsider, $this->store->getKey()));
    }

    public function test_the_platform_channel_is_limited_to_platform_operators(): void
    {
        $operator = $this->platformUser();
        $merchant = $this->memberOf($this->tenant);

        $callback = $this->channel('platform.admins');

        $this->assertTrue($callback($operator));
        $this->assertFalse($callback($merchant));
    }

    public function test_a_created_notification_broadcasts_on_the_owner_channel_with_a_safe_payload(): void
    {
        $user = $this->memberOf($this->tenant);

        $notification = Notification::factory()->forUser($user)->create([
            'tenant_id' => $this->tenant->getKey(),
            'title' => 'Order ORD-1 needs attention',
            'body' => 'A customer has not paid.',
            'action_url' => '/orders/ORD-1',
        ]);

        $notification->load('notifiable');
        $event = new NotificationCreated($notification);
        $payload = $event->broadcastWith();

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-App.Models.User.'.$user->getKey(), (string) $channels[0]);
        $this->assertSame('notification.created', $event->broadcastAs());
        $this->assertSame(1, $payload['unread_count']);
        $this->assertSame($notification->getKey(), $payload['notification']['id']);
        $this->assertArrayNotHasKey('email', $payload['notification']);
        $this->assertArrayNotHasKey('token', $payload['notification']);
    }

    public function test_a_state_change_broadcasts_ids_and_counts_only(): void
    {
        $user = $this->memberOf($this->tenant);

        $event = new NotificationStateChanged($user, 'read_all', [], 0);
        $payload = $event->broadcastWith();

        $this->assertSame('notification.state', $event->broadcastAs());
        $this->assertSame('private-App.Models.User.'.$user->getKey(), (string) $event->broadcastOn()[0]);
        $this->assertSame(['action' => 'read_all', 'ids' => [], 'unread_count' => 0], $payload);
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('body', $payload);
    }

    /**
     * Pull one registered channel callback out of the broadcaster.
     */
    private function channel(string $name): callable
    {
        $channels = Broadcast::getChannels();

        $callback = $channels[$name] ?? null;

        $this->assertNotNull($callback, "The {$name} channel is not registered.");

        return $callback;
    }
}
