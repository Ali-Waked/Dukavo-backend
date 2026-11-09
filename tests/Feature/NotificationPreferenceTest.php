<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The switches a user owns, and the limits of opting out.
 *
 * A preference is not a suggestion: muting in-app hides a row from the bell but
 * keeps the audit record, and a security alert cannot be switched off at all.
 * These tests pin both halves so a future "simplify preferences" change cannot
 * quietly let a merchant miss a password reset.
 */
class NotificationPreferenceTest extends TestCase
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

    public function test_a_partial_update_merges_over_the_resolved_defaults(): void
    {
        $user = $this->memberOf($this->tenant);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['order_updates' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.email.order_updates', false)
            ->assertJsonPath('data.in_app.order_updates', true);
    }

    public function test_security_alerts_cannot_be_disabled_on_any_channel(): void
    {
        $user = $this->memberOf($this->tenant);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['security_alerts' => false],
                'push' => ['security_alerts' => false],
                'in_app' => ['security_alerts' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.email.security_alerts', true)
            ->assertJsonPath('data.push.security_alerts', true)
            ->assertJsonPath('data.in_app.security_alerts', true);
    }

    public function test_muting_in_app_hides_the_row_from_the_bell_but_keeps_it_for_audit(): void
    {
        $user = $this->memberOf($this->tenant);

        $user->forceFill([
            'notification_preferences' => ['in_app' => ['order_updates' => false]],
        ])->save();

        app(NotificationDispatcher::class)->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_ORDER_CREATED,
            tenantId: $this->tenant->getKey(),
            dedupeKey: 'tenant.order.created:order-hidden',
            data: ['order_number' => 'ORD-000001', 'customer' => 'Aye', 'total' => '10.00'],
            actionUrl: '/orders/ORD-000001',
        ));

        $notification = $user->notifications()->firstOrFail();

        $this->assertNotNull($notification->archived_at);
        $this->assertNull($notification->read_at);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_an_outbound_channel_still_sends_when_in_app_is_muted(): void
    {
        $user = $this->memberOf($this->tenant);

        $user->forceFill([
            'notification_preferences' => ['in_app' => ['order_updates' => false]],
        ])->save();

        app(NotificationDispatcher::class)->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_ORDER_CREATED,
            tenantId: $this->tenant->getKey(),
            dedupeKey: 'tenant.order.created:order-email',
            data: ['order_number' => 'ORD-000002', 'customer' => 'Aye', 'total' => '10.00'],
            actionUrl: '/orders/ORD-000002',
        ));

        $notification = $user->notifications()->firstOrFail();

        $this->assertNotNull($notification->archived_at);
        $this->assertSame(1, $notification->deliveries()->where('channel', 'mail')->count());
    }

    public function test_the_channel_catalogue_reports_push_unavailable_without_firebase(): void
    {
        $user = $this->memberOf($this->tenant);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/notifications/channels')
            ->assertOk()
            ->assertJsonPath('data.in_app.available', true)
            ->assertJsonPath('data.push.available', false)
            ->assertJsonPath('data.push.reason', 'push_disabled')
            ->assertJsonPath('data.push.label', 'Push');
    }

    public function test_a_user_without_a_tenant_simply_has_no_notifications(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }
}
