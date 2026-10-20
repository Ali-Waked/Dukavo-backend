<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public self-serve registration is disabled.
 *
 * Dukavo onboards invite-only: a platform operator creates each merchant
 * account, so the anonymous signup endpoint Fortify would otherwise publish
 * must not exist. This test guards against Features::registration() being
 * re-enabled by accident, which would let anyone mint an account and a token
 * without an invitation.
 */
class RegistrationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_registration_endpoint_is_not_routable(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }
}
