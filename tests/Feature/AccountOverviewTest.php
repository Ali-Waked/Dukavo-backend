<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The personal account overview.
 *
 * The summary is an identity view: membership, profile completeness and
 * security posture. It must stay inside the caller's own workspaces and must
 * never surface a merchant's orders, sales, revenue or profit.
 */
class AccountOverviewTest extends TestCase
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

    public function test_the_overview_describes_the_account_and_its_workspaces(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/overview')
            ->assertOk()
            ->assertJsonPath('data.account.type', 'merchant')
            ->assertJsonPath('data.account.email_verified', true)
            ->assertJsonPath('data.account.two_factor_enabled', false)
            ->assertJsonPath('data.workspaces.count', 1)
            ->assertJsonPath('data.workspaces.stores_count', 1)
            ->assertJsonPath('data.workspaces.items.0.id', $this->tenant->getKey())
            ->assertJsonPath('data.workspaces.items.0.name', $this->tenant->name)
            ->assertJsonPath('data.workspaces.items.0.is_owner', true)
            ->assertJsonStructure([
                'data' => [
                    'account' => [
                        'member_since', 'age_days', 'status', 'status_label',
                        'type', 'email_verified', 'phone_verified',
                        'two_factor_enabled', 'password_changed_at', 'last_login_at',
                    ],
                    'profile' => ['percentage', 'completed', 'missing'],
                    'workspaces' => ['count', 'stores_count', 'items'],
                    'security' => ['active_sessions', 'recent_events', 'two_factor_enabled'],
                ],
            ]);

        $this->assertContains('merchant', $response->json('data.workspaces.items.0.roles'));
    }

    public function test_another_workspace_is_not_counted_unless_the_user_belongs_to_it(): void
    {
        $foreign = $this->tenantWithStore();

        $before = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/overview')
            ->assertOk()
            ->assertJsonPath('data.workspaces.count', 1)
            ->assertJsonPath('data.workspaces.stores_count', 1)
            ->json('data.workspaces.items');

        $this->assertNotContains($foreign->getKey(), array_column($before, 'id'));

        $this->user->tenants()->attach($foreign->getKey(), ['is_owner' => false]);

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/v1/profile/overview')
            ->assertOk()
            ->assertJsonPath('data.workspaces.count', 2)
            ->assertJsonPath('data.workspaces.stores_count', 2);
    }

    public function test_the_overview_never_exposes_commercial_performance(): void
    {
        // Give the workspace real business data; none of it belongs in a
        // personal identity summary.
        $this->withinTenant($this->tenant, fn () => Store::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
        ]));

        $payload = strtolower($this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/overview')
            ->assertOk()
            ->getContent());

        foreach (['revenue', 'profit', 'sales', 'orders', 'average_order'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_the_overview_stays_bounded_rather_than_querying_per_row(): void
    {
        $this->user->tenants()->attach($this->tenantWithStore()->getKey(), ['is_owner' => false]);
        $this->user->tenants()->attach($this->tenantWithStore()->getKey(), ['is_owner' => false]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/v1/profile/overview')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Membership roles are the only per-workspace lookup; the rest of the
        // summary is aggregated. The bound leaves room for role caching and
        // token/event counts without allowing a query per store.
        $this->assertLessThanOrEqual(25, count($queries));
    }

    public function test_a_platform_operator_is_described_as_platform_without_workspaces(): void
    {
        $operator = $this->platformUser();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/profile/overview')
            ->assertOk()
            ->assertJsonPath('data.account.type', 'platform')
            ->assertJsonPath('data.workspaces.count', 0)
            ->assertJsonPath('data.workspaces.stores_count', 0);
    }
}
