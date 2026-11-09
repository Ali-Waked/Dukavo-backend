<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationCampaignRecipientStatus;
use App\Enums\NotificationCampaignStatus;
use App\Jobs\DeliverCampaignNotification;
use App\Jobs\PublishNotificationCampaign;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\ChannelAvailability;
use App\Services\Notifications\NotificationCampaignService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Platform announcements.
 *
 * Only Dukavo operators may create them, they reach merchants rather than other
 * operators, a marketing send obeys consent, and replaying a delivery job never
 * creates a second alert. The lifecycle rules are enforced in the service so
 * the API, scheduler and queue cannot disagree about what is allowed.
 */
class NotificationCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->platformUser('super-admin');
        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant);
    }

    public function test_a_merchant_cannot_reach_campaign_administration(): void
    {
        $this->actingAs($this->merchant, 'sanctum')
            ->getJson('/api/v1/platform/notification-campaigns')
            ->assertForbidden();

        $this->actingAs($this->merchant, 'sanctum')
            ->postJson('/api/v1/platform/notification-campaigns', [
                'title' => 'Hello',
                'body' => 'World',
                'channels' => ['in_app'],
                'audience' => ['type' => 'all'],
            ])
            ->assertForbidden();
    }

    public function test_an_operator_creates_a_draft_and_an_idempotency_key_returns_it_again(): void
    {
        $payload = [
            'title' => 'A new report is live',
            'body' => 'Hello {name}, your weekly report is ready.',
            'channels' => ['in_app', 'mail'],
            'audience' => ['type' => 'all'],
            'idempotency_key' => 'campaign-demo-1',
        ];

        $first = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/notification-campaigns', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.channels.0', 'in_app');

        $second = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/notification-campaigns', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, NotificationCampaign::query()->count());
    }

    public function test_the_audience_excludes_operators_inactive_accounts_and_people_without_a_workspace(): void
    {
        $inactive = $this->memberOf($this->tenant);
        $inactive->forceFill(['status' => 'suspended'])->save();

        $tenantless = User::factory()->create();

        $count = app(NotificationCampaignService::class)
            ->audienceQuery(['type' => 'all'])
            ->pluck('id')
            ->all();

        $this->assertContains($this->merchant->getKey(), $count);
        $this->assertNotContains($this->admin->getKey(), $count);
        $this->assertNotContains($inactive->getKey(), $count);
        $this->assertNotContains($tenantless->getKey(), $count);
    }

    public function test_audience_targeting_is_validated_and_counted(): void
    {
        $otherTenant = $this->tenantWithStore();
        $other = $this->memberOf($otherTenant);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/notification-campaigns/audience-count', [
                'audience' => ['type' => 'tenants', 'tenant_ids' => [$this->tenant->getKey()]],
            ])
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/notification-campaigns/audience-count', [
                'audience' => ['type' => 'tenants', 'tenant_ids' => [$this->tenant->getKey(), $otherTenant->getKey()]],
            ])
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertNotSame($this->merchant->getKey(), $other->getKey());
    }

    public function test_a_published_campaign_can_no_longer_be_edited_or_deleted(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/platform/notification-campaigns/{$campaign->getKey()}", [
                'title' => 'Changed',
            ])
            ->assertOk();

        $campaign->forceFill(['status' => NotificationCampaignStatus::Published])->save();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/platform/notification-campaigns/{$campaign->getKey()}", [
                'title' => 'Changed again',
            ])
            ->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/platform/notification-campaigns/{$campaign->getKey()}")
            ->assertStatus(409);
    }

    public function test_scheduling_requires_a_future_time_and_the_scheduler_publishes_it(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-campaigns/{$campaign->getKey()}/schedule", [
                'scheduled_at' => now()->subMinute()->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/notification-campaigns/{$campaign->getKey()}/schedule", [
                'scheduled_at' => now()->addMinute()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $campaign->forceFill(['scheduled_at' => Carbon::now()->subMinute()])->save();

        // The scheduler only flips the row and delegates the fan-out. Faking the
        // bus keeps this a scheduler test rather than a queue integration test;
        // the sync test queue would otherwise drain the whole campaign here.
        Bus::fake();

        Artisan::call('notifications:campaigns');

        $campaign->refresh();

        $this->assertSame(NotificationCampaignStatus::Publishing, $campaign->status);
        $this->assertNotNull($campaign->published_at);
        Bus::assertDispatched(PublishNotificationCampaign::class);
    }

    public function test_a_non_marketing_campaign_writes_the_in_app_notification(): void
    {
        $campaign = $this->campaign();

        $this->deliver($campaign);

        $recipient = $this->recipient($campaign);

        $this->assertSame(NotificationCampaignRecipientStatus::Sent, $recipient->status);
        $this->assertNotNull($recipient->notification_id);

        $notification = Notification::query()->findOrFail($recipient->notification_id);

        $this->assertSame('Hello '.$this->merchant->name, $notification->title);
        $this->assertNull($notification->archived_at);
    }

    public function test_a_marketing_campaign_without_consent_skips_the_recipient(): void
    {
        $campaign = $this->campaign(['is_marketing' => true]);

        $this->deliver($campaign);

        $recipient = $this->recipient($campaign);

        $this->assertSame(NotificationCampaignRecipientStatus::Skipped, $recipient->status);
        $this->assertSame('no_channel_allowed', $recipient->error);
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(1, $campaign->fresh()->skipped_count);
    }

    public function test_marketing_consent_unlocks_the_in_app_copy(): void
    {
        $this->merchant->forceFill([
            'notification_preferences' => ['in_app' => ['product_news' => true]],
        ])->save();

        $campaign = $this->campaign(['is_marketing' => true]);

        $this->deliver($campaign);

        $this->assertSame(NotificationCampaignRecipientStatus::Sent, $this->recipient($campaign)->status);
    }

    public function test_replaying_a_delivery_job_does_not_create_a_second_alert(): void
    {
        $campaign = $this->campaign();

        $this->deliver($campaign);
        $this->deliver($campaign);

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(1, NotificationCampaignRecipient::query()->count());
        $this->assertSame(1, $campaign->fresh()->sent_count);
    }

    public function test_a_cancelled_campaign_stops_delivering(): void
    {
        $campaign = $this->campaign();

        app(NotificationCampaignService::class)->cancel($campaign, $this->admin);

        $this->deliver($campaign);

        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, NotificationCampaignRecipient::query()->count());
    }

    public function test_campaign_delivery_is_rate_limited(): void
    {
        $middleware = (new DeliverCampaignNotification(1, 1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }

    public function test_the_stats_endpoint_reports_failures_by_recipient(): void
    {
        $campaign = $this->campaign();

        NotificationCampaignRecipient::query()->create([
            'campaign_id' => $campaign->getKey(),
            'user_id' => $this->merchant->getKey(),
            'status' => NotificationCampaignRecipientStatus::Failed,
            'error' => 'provider_down',
            'failed_at' => now(),
        ]);

        $campaign->forceFill(['total_recipients' => 1, 'failed_count' => 1])->save();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/platform/notification-campaigns/{$campaign->getKey()}/stats")
            ->assertOk()
            ->assertJsonPath('data.counts.total', 1)
            ->assertJsonPath('data.counts.failed', 1)
            ->assertJsonPath('data.counts.by_status.failed', 1)
            ->assertJsonPath('data.recent_failures.0.error', 'provider_down');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaign(array $attributes = []): NotificationCampaign
    {
        return app(NotificationCampaignService::class)->create($this->admin, [
            'title' => 'Hello {name}',
            'body' => 'A platform announcement.',
            'channels' => ['in_app'],
            'audience' => ['type' => 'users', 'user_ids' => [$this->merchant->getKey()]],
            ...$attributes,
        ]);
    }

    private function deliver(NotificationCampaign $campaign): void
    {
        (new DeliverCampaignNotification((int) $campaign->getKey(), (int) $this->merchant->getKey()))
            ->handle(
                app(NotificationCampaignService::class),
                app(ChannelAvailability::class),
            );
    }

    private function recipient(NotificationCampaign $campaign): NotificationCampaignRecipient
    {
        return NotificationCampaignRecipient::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('user_id', $this->merchant->getKey())
            ->firstOrFail();
    }
}
