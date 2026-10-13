<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The login/logout surface the Vue application boots from.
 *
 * These tests cover the whole authenticated lifecycle rather than a single
 * endpoint, because the contract that matters to the frontend is: sign in, get a
 * token, load `me`, act, sign out, and be refused afterwards.
 */
class AuthEndpointTest extends TestCase
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

    public function test_a_valid_credential_pair_returns_a_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $this->user->email);

        $this->assertIsString($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    public function test_the_returned_token_authenticates_subsequent_requests(): void
    {
        $token = $this->login();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $this->user->email);
    }

    public function test_a_wrong_password_is_refused_without_revealing_the_account(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'not-my-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_email_is_refused_identically(): void
    {
        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'password',
        ]);

        $known = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'wrong',
        ]);

        // The two failures must be indistinguishable, or the endpoint becomes a
        // way to enumerate which merchants exist.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame(
            array_keys($known->json('errors') ?? []),
            array_keys($unknown->json('errors') ?? []),
        );
    }

    public function test_a_suspended_user_cannot_sign_in(): void
    {
        $this->user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $this->user->email,
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertStatus(429);
    }

    public function test_me_requires_authentication_and_returns_json_rather_than_a_redirect(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_me_reports_the_user_their_tenants_roles_and_permissions(): void
    {
        $token = $this->login();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('data.user.email', $this->user->email)
            ->assertJsonPath('data.active_tenant.slug', $this->tenant->slug)
            ->assertJsonPath('data.tenants.0.slug', $this->tenant->slug);

        $this->assertContains('merchant', $response->json('data.roles.0.roles'));
        $this->assertContains('orders.create', $response->json('data.permissions'));
        $this->assertContains('inventory.manage', $response->json('data.permissions'));
        $this->assertNotContains('tenants.delete', $response->json('data.permissions'));
    }

    public function test_the_permission_catalogue_is_grouped_for_the_settings_screen(): void
    {
        $token = $this->login();

        $catalogue = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->json('data.permission_catalogue');

        $this->assertArrayHasKey('orders', $catalogue);
        $this->assertContains('orders.create', $catalogue['orders']);
    }

    public function test_logout_revokes_the_token_it_was_called_with(): void
    {
        // Issued directly rather than through the login endpoint, so the request
        // that follows is a genuine bearer request: no session cookie, exactly
        // like the SPA's.
        $token = $this->user->createToken('spa')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, PersonalAccessToken::query()->count());

        // The token the client still holds must be dead, not merely discouraged.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_logout_only_revokes_the_current_token(): void
    {
        $phone = $this->user->createToken('spa')->plainTextToken;
        $laptop = $this->user->createToken('spa')->plainTextToken;

        $this->assertSame(2, PersonalAccessToken::query()->count());

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Guard instances live for the whole test process; a real request would
        // start from scratch.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        // Signing out on one device must not sign the user out everywhere.
        $this->withHeader('Authorization', 'Bearer '.$laptop)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_logout_works_for_a_browser_client_that_authenticates_by_cookie(): void
    {
        $this->login();

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        // The session is gone, so the cookie no longer authenticates anything.
        // Guard instances live for the whole test process, so they are dropped
        // first: a real next request would resolve the user from scratch.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_a_token_issued_for_a_suspended_user_stops_working(): void
    {
        $token = $this->login();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->user->forceFill(['status' => 'suspended'])->save();

        // Suspension must take effect immediately, not at the next login.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_the_token_helpers_survive_a_user_whose_password_was_changed(): void
    {
        $token = $this->login();

        $this->user->forceFill(['password' => Hash::make('a-brand-new-password')])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    /**
     * Sign in and return the plain-text bearer token.
     */
    private function login(): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk();

        return (string) $response->json('data.token');
    }
}
