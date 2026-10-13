<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public newsletter subscribe/unsubscribe endpoints.
 *
 * The endpoints answer anonymous visitors, so the tests concentrate on the
 * same three failure modes as the waitlist — bots, disclosure of whether an
 * address is known, and unbounded floods — plus the property that separates
 * the newsletter from every other lead-capture form: consent is recorded as
 * its own fact, and a waitlist signup or contact message never implies it.
 */
class NewsletterEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subscription_is_accepted_and_records_fresh_consent(): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => '  Ada.Lovelace@Example.TEST ',
            'source' => 'blog',
        ])->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $subscriber = NewsletterSubscriber::query()->firstOrFail();

        // Whatever casing or whitespace the browser submitted, storage holds
        // one canonical address, and the act of subscribing is the consent.
        $this->assertSame('ada.lovelace@example.test', $subscriber->email);
        $this->assertSame('blog', $subscriber->source);
        $this->assertSame('subscribed', $subscriber->status->value);
        $this->assertNotNull($subscriber->consented_at);
        $this->assertNull($subscriber->unsubscribed_at);
    }

    public function test_a_duplicate_subscription_gets_the_same_answer_and_creates_one_row(): void
    {
        $payload = ['email' => 'duplicate@example.test'];

        $first = $this->postJson('/api/v1/public/newsletter/subscribe', $payload)->assertStatus(202);
        $second = $this->postJson('/api/v1/public/newsletter/subscribe', $payload + ['source' => 'website'])
            ->assertStatus(202);

        // Byte-identical envelopes: an attacker probing addresses can only
        // ever learn "accepted".
        $this->assertSame($first->json('message'), $second->json('message'));
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_an_invalid_email_is_rejected_before_anything_is_stored(): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_the_honeypot_field_silently_drops_a_bot_subscription(): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'bot@example.test',
            'website' => 'https://spam.example',
        ])->assertStatus(202);

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_subscriptions_are_rate_limited_per_ip(): void
    {
        foreach (range(1, 6) as $index) {
            $this->postJson('/api/v1/public/newsletter/subscribe', [
                'email' => "flood-{$index}@example.test",
            ]);
        }

        $this->assertDatabaseCount('newsletter_subscribers', 5);

        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'flood-final@example.test',
        ])->assertStatus(429);

        $this->assertDatabaseCount('newsletter_subscribers', 5);
    }

    public function test_a_waitlist_signup_does_not_subscribe_to_the_newsletter(): void
    {
        $this->postJson('/api/v1/public/waitlist', [
            'email' => 'interested@example.test',
        ])->assertStatus(202);

        $this->assertDatabaseCount('waitlist_entries', 1);
        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_a_newsletter_subscription_does_not_create_a_waitlist_entry(): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'interested@example.test',
        ])->assertStatus(202);

        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_unsubscribe_is_idempotent_and_safe_for_unknown_addresses(): void
    {
        NewsletterSubscriber::factory()->create(['email' => 'leaving@example.test']);

        $first = $this->postJson('/api/v1/public/newsletter/unsubscribe', [
            'email' => 'leaving@example.test',
        ])->assertOk()->assertJsonPath('data', null);

        // Repeating the request changes nothing and answers identically.
        $second = $this->postJson('/api/v1/public/newsletter/unsubscribe', [
            'email' => 'leaving@example.test',
        ])->assertOk();

        $this->assertSame($first->json('message'), $second->json('message'));

        $subscriber = NewsletterSubscriber::query()->where('email', 'leaving@example.test')->firstOrFail();
        $this->assertSame('unsubscribed', $subscriber->status->value);
        $this->assertNotNull($subscriber->unsubscribed_at);

        // An address that was never subscribed gets the same generic answer
        // and creates nothing.
        $this->postJson('/api/v1/public/newsletter/unsubscribe', [
            'email' => 'nobody@example.test',
        ])->assertOk();

        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_resubscribing_after_unsubscribe_records_fresh_consent(): void
    {
        $subscriber = NewsletterSubscriber::factory()->unsubscribed()->create([
            'email' => 'back-again@example.test',
        ]);

        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'back-again@example.test',
        ])->assertStatus(202);

        $subscriber->refresh();

        // The address is subscribed again, its old opt-out is cleared, and the
        // consent timestamp reflects the new decision, not the old one.
        $this->assertSame('subscribed', $subscriber->status->value);
        $this->assertNull($subscriber->unsubscribed_at);
        $this->assertNotNull($subscriber->consented_at);
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_public_endpoints_never_disclose_stored_addresses(): void
    {
        NewsletterSubscriber::factory()->create(['email' => 'hidden@example.test']);

        $subscribe = $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'other@example.test',
        ])->assertStatus(202);

        $unsubscribe = $this->postJson('/api/v1/public/newsletter/unsubscribe', [
            'email' => 'other@example.test',
        ])->assertOk();

        // Neither envelope echoes an existing address from anywhere in the
        // response, and neither carries a subscriber payload.
        foreach ([$subscribe, $unsubscribe] as $response) {
            $response->assertJsonPath('data', null);
            $this->assertStringNotContainsString('hidden@example.test', $response->getContent());
        }
    }

    public function test_a_subscription_leaves_no_audit_trail(): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => 'quiet@example.test',
        ])->assertStatus(202);

        // Anonymous consent must not flood the audit log the platform team
        // reads; the row itself is the record.
        $this->assertSame(0, AuditLog::query()->count());
    }
}
