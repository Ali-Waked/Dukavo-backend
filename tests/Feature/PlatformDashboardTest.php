<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WaitlistStatus;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform overview.
 *
 * The endpoint exists so a platform operator can see real Dukavo-wide figures
 * without being dropped into a merchant workspace. It must be reachable by an
 * operator with no tenant context, and closed to everyone else.
 */
class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/platform/dashboard')
            ->assertStatus(401);
    }

    public function test_a_tenant_merchant_cannot_reach_it(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertForbidden();
    }

    public function test_a_platform_operator_sees_real_platform_counts_without_a_tenant_header(): void
    {
        $operator = $this->platformUser('super-admin');

        $this->tenantWithStore();
        Tenant::factory()->suspended()->create();

        WaitlistEntry::factory()->count(2)->create();
        WaitlistEntry::factory()->invited()->create();

        ContactMessage::factory()->count(2)->create();
        ContactMessage::factory()->read()->create();

        NewsletterSubscriber::factory()->count(3)->create();
        NewsletterSubscriber::factory()->unsubscribed()->create();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenants.total', 2)
            ->assertJsonPath('data.tenants.active', 1)
            ->assertJsonPath('data.tenants.suspended', 1)
            ->assertJsonPath('data.waitlist.total', 3)
            ->assertJsonPath('data.waitlist.pending', 2)
            ->assertJsonPath('data.contact_messages.total', 3)
            ->assertJsonPath('data.contact_messages.unresolved', 2)
            ->assertJsonPath('data.newsletter_subscribers.total', 4)
            ->assertJsonPath('data.newsletter_subscribers.subscribed', 3)
            // The operator themself is the only registered user, and the only
            // account holding a platform role.
            ->assertJsonPath('data.users.total', 1)
            ->assertJsonPath('data.users.platform', 1);
    }

    public function test_it_reports_new_and_previous_period_counts_for_the_selected_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));
        $operator = $this->platformUser('super-admin');

        // Current 30-day window: 2026-05-17 .. 2026-06-15.
        Tenant::factory()->create(['created_at' => '2026-06-01 10:00:00']);
        Tenant::factory()->create(['created_at' => '2026-05-20 10:00:00']);
        // Preceding window: 2026-04-17 .. 2026-05-16.
        Tenant::factory()->create(['created_at' => '2026-05-10 10:00:00']);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/dashboard?range=30d')
            ->assertOk()
            ->assertJsonPath('data.range.key', '30d')
            ->assertJsonPath('data.tenants.total', 3)
            ->assertJsonPath('data.tenants.new', 2)
            ->assertJsonPath('data.tenants.previous_new', 1);
    }

    public function test_it_rejects_an_unknown_range(): void
    {
        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/dashboard?range=13q')
            ->assertStatus(422)
            ->assertJsonValidationErrors('range');
    }

    public function test_it_zero_fills_the_series_across_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));

        $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/dashboard?range=7d')
            ->assertOk()
            ->assertJsonCount(7, 'data.series')
            ->assertJsonPath('data.series.0.bucket', '2026-06-09')
            ->assertJsonPath('data.series.6.bucket', '2026-06-15')
            ->assertJsonPath('data.series.0.tenants', 0)
            ->assertJsonPath('data.series.6.tenants', 0);
    }

    public function test_it_buckets_the_twelve_month_range_by_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));

        $response = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/dashboard?range=12m')
            ->assertOk()
            ->assertJsonPath('data.range.bucket', 'month')
            ->assertJsonCount(12, 'data.series');

        $this->assertSame('2025-07', $response->json('data.series.0.bucket'));
        $this->assertSame('2026-06', $response->json('data.series.11.bucket'));
    }

    public function test_it_reports_status_distribution_from_real_rows(): void
    {
        $operator = $this->platformUser('super-admin');

        Tenant::factory()->create();
        Tenant::factory()->suspended()->create();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status.tenants.0.value', 'active')
            ->assertJsonPath('data.status.tenants.0.count', 1)
            ->assertJsonPath('data.status.tenants.1.value', 'suspended')
            ->assertJsonPath('data.status.tenants.1.count', 1);
    }

    public function test_it_classifies_users_into_platform_member_and_both_buckets(): void
    {
        $operator = $this->platformUser('super-admin');
        $tenant = $this->tenantWithStore();
        $this->memberOf($tenant, 'merchant');

        // The same person may be Dukavo staff *and* a workspace member; they
        // must land in `both`, never twice in the two single buckets.
        $dual = $this->platformUser('dukavo-admin');
        $dual->roleAssignments()->create([
            'role_id' => Role::query()->where('slug', 'merchant')->firstOrFail()->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);
        $dual->tenants()->attach($tenant->getKey(), ['is_owner' => false]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.users.total', 3)
            ->assertJsonPath('data.users.platform', 2)
            ->assertJsonPath('data.users.members', 2)
            ->assertJsonPath('data.users.platform_only', 1)
            ->assertJsonPath('data.users.members_only', 1)
            ->assertJsonPath('data.users.both', 1)
            ->assertJsonPath('data.status.user_accounts.0.value', 'platform_only')
            ->assertJsonPath('data.status.user_accounts.0.count', 1)
            ->assertJsonPath('data.status.user_accounts.1.value', 'members_only')
            ->assertJsonPath('data.status.user_accounts.1.count', 1)
            ->assertJsonPath('data.status.user_accounts.2.value', 'both')
            ->assertJsonPath('data.status.user_accounts.2.count', 1);
    }

    public function test_it_reports_user_status_distribution(): void
    {
        $operator = $this->platformUser('super-admin');

        User::factory()->invited()->create();
        User::factory()->suspended()->create();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status.users.0.value', 'active')
            ->assertJsonPath('data.status.users.0.count', 1)
            ->assertJsonPath('data.status.users.1.value', 'invited')
            ->assertJsonPath('data.status.users.1.count', 1)
            ->assertJsonPath('data.status.users.2.value', 'suspended')
            ->assertJsonPath('data.status.users.2.count', 1);
    }

    public function test_it_counts_joined_waitlist_signups(): void
    {
        $operator = $this->platformUser('super-admin');

        WaitlistEntry::factory()->create();
        WaitlistEntry::factory()->invited()->create();
        WaitlistEntry::factory()->create(['status' => WaitlistStatus::Joined]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.waitlist.total', 3)
            ->assertJsonPath('data.waitlist.pending', 1)
            ->assertJsonPath('data.waitlist.joined', 1);
    }
}
