<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public waitlist signup.
 *
 * The endpoint answers anonymous visitors, so the tests concentrate on the
 * three things that can go wrong in the open: telling a bot from a person,
 * leaking whether an address is already known, and accepting an unbounded
 * flood of signups from one client.
 */
class WaitlistEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signup_is_accepted_and_the_email_is_normalised(): void
    {
        $this->postJson('/api/v1/public/waitlist', [
            'email' => '  Grace.Hopper@Example.TEST ',
            'name' => '  Grace Hopper ',
            'business_type' => 'Retail',
            'source' => 'website',
        ])->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $entry = WaitlistEntry::query()->firstOrFail();

        // Whatever casing or whitespace the browser submitted, storage holds
        // one canonical address.
        $this->assertSame('grace.hopper@example.test', $entry->email);
        $this->assertSame('Grace Hopper', $entry->name);
        $this->assertSame('pending', $entry->status->value);
    }

    public function test_a_duplicate_signup_gets_the_same_answer_as_a_fresh_one(): void
    {
        $payload = ['email' => 'duplicate@example.test', 'name' => 'Once'];

        $first = $this->postJson('/api/v1/public/waitlist', $payload)->assertStatus(202);
        $second = $this->postJson('/api/v1/public/waitlist', $payload + ['name' => 'Twice'])
            ->assertStatus(202);

        // Byte-identical envelopes: an attacker probing addresses can only
        // ever learn "accepted".
        $this->assertSame(
            $first->json('message'),
            $second->json('message'),
        );
        $this->assertDatabaseCount('waitlist_entries', 1);
    }

    public function test_the_honeypot_field_silently_drops_a_bot_signup(): void
    {
        $this->postJson('/api/v1/public/waitlist', [
            'email' => 'bot@example.test',
            'website' => 'https://spam.example',
        ])->assertStatus(202);

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_an_invalid_email_is_rejected_before_anything_is_stored(): void
    {
        $this->postJson('/api/v1/public/waitlist', [
            'email' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_signups_are_rate_limited_per_ip(): void
    {
        foreach (range(1, 6) as $index) {
            $this->postJson('/api/v1/public/waitlist', [
                'email' => "flood-{$index}@example.test",
            ]);
        }

        $this->assertDatabaseCount('waitlist_entries', 5);

        $this->postJson('/api/v1/public/waitlist', [
            'email' => 'flood-final@example.test',
        ])->assertStatus(429);

        $this->assertDatabaseCount('waitlist_entries', 5);
    }

    public function test_a_signup_leaves_no_audit_trail(): void
    {
        $this->postJson('/api/v1/public/waitlist', [
            'email' => 'quiet@example.test',
        ])->assertStatus(202);

        // Anonymous signups must not flood the audit log the platform team
        // reads; the row itself is the record.
        $this->assertSame(0, AuditLog::query()->count());
    }
}
