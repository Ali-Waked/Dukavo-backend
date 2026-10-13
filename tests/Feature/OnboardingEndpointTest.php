<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First-run onboarding: turning a fresh account into a working workspace.
 *
 * The two properties worth protecting are that the caller ends up the owner
 * of a merchant role (never anything else the payload might have asked for)
 * and that a failure mid-provision leaves nothing behind at all.
 */
class OnboardingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_an_authenticated_user_can_provision_their_first_workspace(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/onboarding/tenant', [
                'name' => 'Baker Street Grocers',
                'store_name' => 'Main Street',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Baker Street Grocers')
            ->assertJsonPath('data.slug', 'baker-street-grocers');

        $tenant = $response->json('data.id');
        $this->assertDatabaseHas('stores', [
            'tenant_id' => $tenant,
            'name' => 'Main Street',
        ]);

        // The contact email falls back to the registering account, so the
        // workspace is reachable even when the form left it blank.
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant,
            'contact_email' => $this->user->email,
        ]);

        $this->assertTrue((bool) $this->user->fresh()->tenants()->whereKey($tenant)->first()->pivot->is_owner);
        $this->assertSame(
            'merchant',
            $this->user->fresh()->roleAssignments()
                ->where('tenant_id', $tenant)
                ->first()
                ->role
                ->slug,
        );
    }

    public function test_a_replayed_idempotency_key_returns_the_same_workspace(): void
    {
        $payload = ['name' => 'Replayed Co', 'store_name' => 'Main Street'];
        $headers = ['Idempotency-Key' => 'submit-abc-123'];

        $first = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/onboarding/tenant', $payload, $headers);

        $first->assertCreated()->assertJsonPath('success', true);
        $workspace = $first->json('data.id');

        // A double-submitted form or a retry after a dropped response replays
        // the key; it must resolve to the workspace already built, not mint a
        // second one.
        $second = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/onboarding/tenant', $payload, $headers);

        $second->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $workspace);

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('tenant_user', 1);
        $this->assertDatabaseCount('user_roles', 1);
    }

    public function test_onboarding_requires_an_authenticated_user(): void
    {
        $this->postJson('/api/v1/onboarding/tenant', ['name' => 'No Account Yet'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_a_failed_provision_leaves_no_partial_workspace_behind(): void
    {
        // Simulate a misconfigured installation: the merchant role the owner
        // must receive does not exist, so the lookup fails *inside* the
        // transaction.
        Role::query()->where('slug', 'merchant')->delete();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/onboarding/tenant', ['name' => 'Half Built'])
            ->assertNotFound();

        // Not one row of the workspace may survive: no tenant, no store, and
        // no dangling membership for the caller.
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('tenant_user', 0);
        $this->assertDatabaseCount('user_roles', 0);
    }

    public function test_a_suspended_user_cannot_provision_a_workspace(): void
    {
        $this->user->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/onboarding/tenant', ['name' => 'Suspended Owner'])
            ->assertStatus(401);

        $this->assertDatabaseCount('tenants', 0);
    }
}
