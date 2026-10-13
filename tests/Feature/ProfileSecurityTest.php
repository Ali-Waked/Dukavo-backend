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
 * The security surface: changing the password and managing signed-in sessions.
 *
 * The invariant worth proving is not the HTTP shape but the blast radius. A
 * password change has to sign out every other device, and a session revocation
 * has to stop at the boundary of the caller's own tokens — otherwise a revoke
 * endpoint becomes a universal logout for every account in the system.
 *
 * Requests are made with bearer tokens rather than through the login endpoint:
 * a Sanctum guard instance lives for the whole test process, so every token
 * request is preceded by `forgetGuards()` — the same reset a fresh HTTP request
 * would get naturally.
 */
class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Str0nge!Passw0rd';

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->user = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $this->asToken($this->user->createToken('browser')->plainTextToken)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'wrong-old-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_an_unmatched_confirmation_is_rejected(): void
    {
        $this->asToken($this->user->createToken('browser')->plainTextToken)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'different',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_changing_the_password_signs_out_every_other_device(): void
    {
        $current = $this->user->createToken('browser')->plainTextToken;
        $phone = $this->user->createToken('phone')->plainTextToken;
        $laptop = $this->user->createToken('laptop')->plainTextToken;

        $this->asToken($current)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));

        // Only the device that made the change keeps a token.
        $this->assertSame(
            1,
            PersonalAccessToken::query()->where('tokenable_id', $this->user->getKey())->count(),
        );

        // The device that changed the password stays signed in...
        $this->asToken($current)->getJson('/api/v1/me')->assertOk();

        // ...every other signed-in device is cut off.
        $this->asToken($phone)->getJson('/api/v1/me')->assertStatus(401);
        $this->asToken($laptop)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_signed_in_devices_are_listed_with_the_current_one_marked(): void
    {
        $this->user->createToken('phone');
        $this->user->createToken('laptop');
        $current = $this->user->createToken('browser')->plainTextToken;

        $sessions = $this->asToken($current)
            ->getJson('/api/v1/profile/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $sessions);
        $this->assertCount(1, array_filter($sessions, fn (array $session): bool => $session['is_current']));
        $this->assertNotSame('', $sessions[0]['name']);
    }

    public function test_a_single_session_can_be_revoked_by_id(): void
    {
        $phone = $this->user->createToken('phone')->plainTextToken;
        $laptop = $this->user->createToken('laptop')->plainTextToken;

        $phoneId = PersonalAccessToken::query()
            ->where('tokenable_id', $this->user->getKey())
            ->where('name', 'phone')
            ->firstOrFail()
            ->getKey();

        $this->asToken($phone)
            ->deleteJson("/api/v1/profile/sessions/{$phoneId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->asToken($phone)->getJson('/api/v1/me')->assertStatus(401);
        $this->asToken($laptop)->getJson('/api/v1/me')->assertOk();
    }

    public function test_revoking_a_token_that_is_not_yours_is_a_404(): void
    {
        $stranger = $this->memberOf($this->tenant, 'staff');
        $strangerToken = $stranger->createToken('phone')->plainTextToken;

        $strangerId = PersonalAccessToken::query()
            ->where('tokenable_id', $stranger->getKey())
            ->firstOrFail()
            ->getKey();

        $this->asToken($this->user->createToken('browser')->plainTextToken)
            ->deleteJson("/api/v1/profile/sessions/{$strangerId}")
            ->assertNotFound();

        // The stranger's device still works.
        $this->asToken($strangerToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_signing_out_other_devices_keeps_the_current_one(): void
    {
        $current = $this->user->createToken('browser')->plainTextToken;
        $this->user->createToken('phone');
        $this->user->createToken('laptop');

        $this->asToken($current)
            ->deleteJson('/api/v1/profile/sessions')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            1,
            PersonalAccessToken::query()->where('tokenable_id', $this->user->getKey())->count(),
        );

        $this->asToken($current)->getJson('/api/v1/me')->assertOk();
    }

    /**
     * Act as a bearer-token client, resetting guards as a real request would.
     */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
