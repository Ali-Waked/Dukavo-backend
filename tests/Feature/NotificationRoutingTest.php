<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversations\ConversationService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Services\Profile\ProfileService;
use App\Services\Tenancy\TenantService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notification routing for directed work and platform lifecycle events.
 *
 * A notification is not a broadcast: an assignment belongs to one agent, a
 * delivery failure to the person who sent the message, an approval request to
 * people who can actually approve, and a workspace suspension to platform
 * operators rather than the merchant locked out of the workspace. These tests
 * pin the audience, the dedupe behaviour and the fact that role-aware defaults
 * survive a partial preference update.
 */
class NotificationRoutingTest extends TestCase
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

    public function test_assignment_notifies_only_the_assigned_agent(): void
    {
        $assigner = $this->memberOf($this->tenant, 'merchant');
        $assignee = $this->memberOf($this->tenant, 'merchant');
        $bystander = $this->memberOf($this->tenant, 'merchant');
        $outsider = $this->memberOf($this->tenantWithStore(), 'merchant');

        $conversation = $this->conversationFor($this->tenant);

        $this->actingAs($assigner);
        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)
            ->assign($conversation, (int) $assignee->getKey()));

        $notification = $assignee->notifications()
            ->where('event_key', Events::TENANT_CONVERSATION_ASSIGNED)
            ->firstOrFail();

        $this->assertSame($this->tenant->getKey(), $notification->tenant_id);
        $this->assertSame('/conversations', $notification->action_url);
        $this->assertSame(0, $assigner->notifications()->count());
        $this->assertSame(0, $bystander->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_delivery_failure_notifies_the_sender_once(): void
    {
        $sender = $this->memberOf($this->tenant, 'merchant');
        $colleague = $this->memberOf($this->tenant, 'merchant');

        $conversation = $this->conversationFor($this->tenant);

        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)->addMessage(
            $conversation,
            MessageDirection::Outbound,
            [
                'body' => 'Your order is on the way.',
                'user_id' => $sender->getKey(),
                'external_id' => 'wamid.failure-1',
                'delivery_status' => 'sent',
            ],
        ));

        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)
            ->markDelivery(['wamid.failure-1'], 'failed', 'Recipient has blocked the business'));

        // A repeated provider receipt must not raise a second alert.
        $this->withinTenant($this->tenant, fn () => app(ConversationService::class)
            ->markDelivery(['wamid.failure-1'], 'failed', 'Recipient has blocked the business'));

        $notification = $sender->notifications()
            ->where('event_key', Events::TENANT_MESSAGE_FAILED)
            ->firstOrFail();

        $this->assertStringContainsString('Recipient has blocked the business', $notification->body);
        $this->assertSame(1, $sender->notifications()
            ->where('event_key', Events::TENANT_MESSAGE_FAILED)
            ->count());
        $this->assertSame(0, $colleague->notifications()->count());
    }

    public function test_ai_review_request_reaches_approvers_but_not_the_proposer(): void
    {
        $proposer = $this->memberOf($this->tenant, 'merchant');
        $approver = $this->memberOf($this->tenant, 'staff');
        $bystander = User::factory()->create();
        $bystander->tenants()->attach($this->tenant->getKey(), ['is_owner' => false]);
        $outsider = $this->memberOf($this->tenantWithStore(), 'staff');

        app(NotificationDispatcher::class)->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_AI_ACTION_AWAITING_REVIEW,
            tenantId: (int) $this->tenant->getKey(),
            dedupeKey: 'tenant.ai_action.awaiting_review:test-action',
            data: ['summary' => 'Adjust stock for SKU-1'],
            actionUrl: '/ai/approvals',
            actorId: (int) $proposer->getKey(),
        ));

        $notification = $approver->notifications()
            ->where('event_key', Events::TENANT_AI_ACTION_AWAITING_REVIEW)
            ->firstOrFail();

        $this->assertSame('/ai/approvals', $notification->action_url);
        $this->assertSame(0, $proposer->notifications()->count());
        $this->assertSame(0, $bystander->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_tenant_suspension_and_reactivation_notify_platform_operators_only_on_transition(): void
    {
        $operator = $this->platformUser('super-admin');
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $tenants = app(TenantService::class);

        $tenants->suspend($this->tenant);
        $tenants->suspend($this->tenant->fresh());

        $this->assertSame(1, $operator->notifications()
            ->where('event_key', Events::PLATFORM_TENANT_SUSPENDED)
            ->count());

        $tenants->activate($this->tenant->fresh());
        $tenants->activate($this->tenant->fresh());

        $this->assertSame(1, $operator->notifications()
            ->where('event_key', Events::PLATFORM_TENANT_REACTIVATED)
            ->count());
        $this->assertSame(0, $merchant->notifications()->count());
    }

    public function test_operational_preference_defaults_follow_the_users_permissions(): void
    {
        $staff = $this->memberOf($this->tenant, 'staff');
        $defaults = $staff->notificationPreferences((int) $this->tenant->getKey());

        $this->assertTrue($defaults['email']['conversation_assignments']);
        $this->assertTrue($defaults['email']['delivery_failures']);
        $this->assertTrue($defaults['email']['approvals']);
        $this->assertTrue($defaults['whatsapp']['conversation_assignments']);
        $this->assertTrue($defaults['whatsapp']['delivery_failures']);

        $withoutRole = User::factory()->create();
        $plain = $withoutRole->notificationPreferences((int) $this->tenant->getKey());

        $this->assertFalse($plain['email']['conversation_assignments']);
        $this->assertFalse($plain['email']['delivery_failures']);
        $this->assertFalse($plain['email']['approvals']);
        $this->assertFalse($plain['whatsapp']['conversation_assignments']);

        // An explicit stored choice always wins over the role-aware default.
        $staff->forceFill([
            'notification_preferences' => ['email' => ['conversation_assignments' => false]],
        ])->save();

        $explicit = $staff->fresh()->notificationPreferences((int) $this->tenant->getKey());
        $this->assertFalse($explicit['email']['conversation_assignments']);
        $this->assertTrue($explicit['email']['delivery_failures']);
    }

    public function test_a_partial_preference_update_does_not_freeze_role_defaults(): void
    {
        $staff = $this->memberOf($this->tenant, 'staff');

        app(ProfileService::class)->updateNotificationPreferences($staff, [
            'email' => ['order_updates' => false],
        ]);

        $preferences = $staff->fresh()->notificationPreferences((int) $this->tenant->getKey());

        $this->assertFalse($preferences['email']['order_updates']);
        $this->assertTrue($preferences['email']['conversation_assignments']);
        $this->assertTrue($preferences['whatsapp']['conversation_assignments']);
    }

    private function conversationFor(Tenant $tenant): Conversation
    {
        $customer = $this->withinTenant($tenant, fn (): Customer => Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
        ]));

        return $this->withinTenant($tenant, fn (): Conversation => app(ConversationService::class)
            ->openForCustomer($customer, 'whatsapp', '1555000'.random_int(1000, 9999)));
    }
}
