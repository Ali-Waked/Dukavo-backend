<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * TOTP two-factor enrolment, recovery codes and the login challenge.
 *
 * Two invariants run through all of it: a secret or recovery code is readable
 * only at the moment it is created, and a challenge is single-use, expiring and
 * attempt-limited. Neither a stolen session nor a stolen password is enough to
 * remove the factor.
 */
class TwoFactorAuthenticationTest extends TestCase
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

    public function test_setup_returns_a_secret_and_qr_once_and_leaves_the_factor_pending(): void
    {
        $response = $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/setup')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['secret', 'provisioning_uri', 'qr_svg', 'issuer', 'account'],
            ]);

        $secret = $response->json('data.secret');

        $this->assertNotEmpty($secret);
        $this->assertStringContainsString('otpauth://totp/', $response->json('data.provisioning_uri'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.qr_svg'));

        $this->asToken($this->token())
            ->getJson('/api/v1/profile/two-factor')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.pending', true)
            ->assertJsonPath('data.recovery_codes_count', 0);
    }

    public function test_an_invalid_code_cannot_confirm_enrolment(): void
    {
        $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/setup')
            ->assertOk();

        $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/confirm', ['code' => 'not-a-code'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $this->assertDatabaseHas('security_events', [
            'user_id' => $this->user->getKey(),
            'event' => SecurityEventType::TwoFactorChallengeFailed->value,
            'status' => 'failure',
        ]);
    }

    public function test_a_valid_code_enables_the_factor_and_shows_recovery_codes_once(): void
    {
        $secret = $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/setup')
            ->assertOk()
            ->json('data.secret');

        $response = $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/confirm', ['code' => $this->currentOtp($secret)])
            ->assertOk()
            ->assertJsonPath('data.two_factor.enabled', true)
            ->assertJsonPath('data.two_factor.pending', false)
            ->assertJsonPath('data.two_factor.recovery_codes_count', 8);

        $codes = $response->json('data.codes');

        $this->assertCount(8, $codes);
        $this->assertNotContains($secret, $codes);

        $user = $this->user->fresh();
        $this->assertTrue($user->hasTwoFactorEnabled());

        // Neither credential is readable in the clear from the database.
        $raw = DB::table('users')->where('id', $user->getKey())->first();
        $this->assertNotSame($secret, $raw->two_factor_secret);
        $this->assertStringNotContainsString($secret, (string) $raw->two_factor_secret);

        $hashes = $user->two_factor_recovery_codes;
        $this->assertCount(8, $hashes);
        $this->assertTrue(Hash::check($codes[0], $hashes[0]));
        $this->assertNotSame($codes[0], $hashes[0]);

        // The status endpoint reports posture, never the credentials.
        $status = $this->asToken($this->token())
            ->getJson('/api/v1/profile/two-factor')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertArrayNotHasKey('secret', $status->json('data'));
        $this->assertArrayNotHasKey('codes', $status->json('data'));
        $this->assertArrayNotHasKey('two_factor_secret', $status->json('data'));
    }

    public function test_setup_is_refused_when_the_factor_is_already_enabled(): void
    {
        $this->enableTwoFactor();

        $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/setup')
            ->assertStatus(409);
    }

    public function test_recovery_codes_can_be_regenerated_only_with_a_current_code(): void
    {
        [$secret, $oldCodes] = $this->enableTwoFactor();
        $hashesBefore = $this->user->fresh()->two_factor_recovery_codes;

        $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/recovery-codes', ['code' => 'not-a-code'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        // A failed attempt changes nothing.
        $this->assertSame($hashesBefore, $this->user->fresh()->two_factor_recovery_codes);

        $response = $this->asToken($this->token())
            ->postJson('/api/v1/profile/two-factor/recovery-codes', ['code' => $this->currentOtp($secret)])
            ->assertOk()
            ->assertJsonPath('data.recovery_codes_count', 8);

        $newCodes = $response->json('data.codes');

        $this->assertCount(8, $newCodes);
        $this->assertNotEquals($oldCodes, $newCodes);

        // The old set is gone, so a leaked old code cannot be replayed.
        $this->assertFalse(
            collect($this->user->fresh()->two_factor_recovery_codes)
                ->contains(fn (string $hash): bool => Hash::check($oldCodes[0], $hash)),
        );
        $this->assertTrue(
            collect($this->user->fresh()->two_factor_recovery_codes)
                ->contains(fn (string $hash): bool => Hash::check($newCodes[0], $hash)),
        );

        $this->assertDatabaseHas('security_events', [
            'user_id' => $this->user->getKey(),
            'event' => SecurityEventType::RecoveryCodesRegenerated->value,
        ]);
    }

    public function test_disabling_requires_both_the_password_and_a_current_factor(): void
    {
        [$secret] = $this->enableTwoFactor();

        $token = $this->token();

        $this->asToken($token)
            ->deleteJson('/api/v1/profile/two-factor', [
                'password' => 'wrong-password',
                'code' => $this->currentOtp($secret),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->asToken($token)
            ->deleteJson('/api/v1/profile/two-factor', [
                'password' => 'password',
                'code' => 'not-a-code',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());

        $this->asToken($token)
            ->deleteJson('/api/v1/profile/two-factor', [
                'password' => 'password',
                'code' => $this->currentOtp($secret),
            ])
            ->assertOk()
            ->assertJsonPath('data.two_factor.enabled', false);

        $user = $this->user->fresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->assertDatabaseHas('security_events', [
            'user_id' => $user->getKey(),
            'event' => SecurityEventType::TwoFactorDisabled->value,
        ]);
    }

    public function test_a_recovery_code_can_disable_the_factor_when_the_authenticator_is_lost(): void
    {
        [, $codes] = $this->enableTwoFactor();

        $this->asToken($this->token())
            ->deleteJson('/api/v1/profile/two-factor', [
                'password' => 'password',
                'recovery_code' => $codes[0],
            ])
            ->assertOk()
            ->assertJsonPath('data.two_factor.enabled', false);
    }

    public function test_login_returns_a_challenge_not_a_session_for_a_protected_account(): void
    {
        [$secret] = $this->enableTwoFactor();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonPath('data.methods', ['totp', 'recovery_code'])
            ->assertJsonMissingPath('data.token');

        $token = (string) $response->json('data.challenge_token');
        $this->assertNotEmpty($token);

        // The password step alone must not leave an authenticated web session.
        $this->assertFalse($this->app['auth']->guard('web')->check());

        // Only a hash of the challenge is stored.
        $stored = DB::table('two_factor_challenges')->latest('id')->first();
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);

        $signedIn = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $token,
            'code' => $this->currentOtp($secret),
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($signedIn->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The challenge is single-use.
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $token,
            'code' => $this->currentOtp($secret),
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    }

    public function test_a_challenge_cannot_be_guessed_without_the_second_factor(): void
    {
        $this->enableTwoFactor();

        $challenge = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk()->json('data.challenge_token');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge,
            'code' => 'not-a-code',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge,
            'recovery_code' => 'WRONG-CODE1',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertCount(4, $this->user->fresh()->two_factor_recovery_codes);

        // The failed attempts are visible to the owner, without the code.
        $event = SecurityEvent::query()
            ->where('user_id', $this->user->getKey())
            ->where('event', SecurityEventType::TwoFactorChallengeFailed)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('code', $event->metadata ?? []);
        $this->assertArrayNotHasKey('recovery_code', $event->metadata ?? []);
        $this->assertStringNotContainsString('WRONG-CODE1', json_encode($event->metadata));
    }

    public function test_a_challenge_locks_after_the_configured_attempt_limit(): void
    {
        config(['dukavo.two_factor.max_attempts' => 2]);
        $this->enableTwoFactor();

        $challenge = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk()->json('data.challenge_token');

        foreach (['bad-code-1', 'bad-code-2'] as $bad) {
            $this->postJson('/api/v1/auth/two-factor-challenge', [
                'challenge_token' => $challenge,
                'code' => $bad,
            ])->assertUnprocessable()->assertJsonValidationErrors('code');
        }

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge,
            'code' => 'bad-code-3',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');

        $this->assertNotNull(DB::table('two_factor_challenges')->latest('id')->first()->consumed_at);
    }

    public function test_an_expired_challenge_is_refused(): void
    {
        $this->enableTwoFactor();

        config(['dukavo.two_factor.challenge_ttl_minutes' => 1]);

        $challenge = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk()->json('data.challenge_token');

        $this->travel(2)->minutes();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge,
            'recovery_code' => 'ANY-CODE-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    }

    public function test_a_recovery_code_signs_in_once_and_is_then_consumed(): void
    {
        [, $codes] = $this->enableTwoFactor();

        $first = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk()->json('data.challenge_token');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $first,
            'recovery_code' => $codes[0],
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');

        $this->assertCount(3, $this->user->fresh()->two_factor_recovery_codes);

        $second = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk()->json('data.challenge_token');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $second,
            'recovery_code' => $codes[0],
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    /**
     * Enrol the account directly, bypassing the setup request.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function enableTwoFactor(): array
    {
        $secret = $this->currentSecret();
        $codes = ['AAAAA-11111', 'BBBBB-22222', 'CCCCC-33333', 'DDDDD-44444'];

        $this->user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $codes,
            ),
        ])->save();

        return [$secret, $codes];
    }

    private function currentSecret(): string
    {
        return (string) app(Google2FA::class)->generateSecretKey();
    }

    private function currentOtp(string $secret): string
    {
        return (string) app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function token(): string
    {
        return $this->user->createToken('browser')->plainTextToken;
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
