<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The registration endpoint the SPA signs up new merchants through.
 *
 * The contract that matters: an accepted signup answers with the same
 * envelope as login — user plus bearer token — so the very next request can
 * already be authenticated, and a signup flood is capped per IP.
 */
class RegistrationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_valid_signup_returns_the_user_and_a_usable_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada@example.test')
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = (string) $response->json('data.token');
        $this->assertIsString($token);
        $this->assertDatabaseCount('users', 1);
        $this->assertNotNull(User::query()->firstOrFail()->last_login_at);

        // Registration hands over a token, so it must actually work — the SPA
        // would otherwise have to log in again immediately after signing up.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ada@example.test');
    }

    public function test_a_duplicate_email_is_rejected_as_a_validation_error(): void
    {
        User::factory()->create(['email' => 'ada@example.test']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Again',
            'email' => 'ada@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_password_that_fails_the_password_rule_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak',
            'email' => 'weak@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        // The framework hashes limiter keys (md5 of the limiter name and the
        // `by` value), and the application is shared across every test in a
        // process, so other tests' registrations would silently advance this
        // limiter and make the assertion order-dependent. Start from a clean
        // slate for this IP.
        $this->app['cache']->flush();

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Flood',
                'email' => 'flood-'.uniqid('', true).'@example.test',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])->assertStatus(201);

            // End the session the signup just minted so the next attempt is
            // anonymous again. A per-IP flood sends one request per session;
            // reusing the previous account's session would let the `guest`
            // middleware short-circuit the limiter this test is guarding.
            $this->app['auth']->forgetGuards();
            $this->app['session']->driver()->invalidate();
        }

        // The fifth signup succeeded; the sixth is refused before any user
        // row is written.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Flood',
            'email' => 'flood-final@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(429)->assertJsonPath('success', false);

        $this->assertDatabaseCount('users', 5);
    }
}
