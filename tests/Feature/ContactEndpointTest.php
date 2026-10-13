<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The public contact form.
 *
 * The promise the visitor sees is "your message reached the platform": the
 * tests hold the endpoint to storing the row even when email fails, keeping
 * the reply address out of the visitor's control, and staying polite under
 * repeated submissions.
 */
class ContactEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const array VALID = [
        'name' => 'Katherine Johnson',
        'email' => 'katherine@example.test',
        'subject' => 'Question about multi store inventory',
        'message' => 'Do movements roll up across stores?',
    ];

    public function test_a_message_is_stored_and_the_platform_inbox_is_notified(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/public/contact', self::VALID)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $message = ContactMessage::query()->firstOrFail();
        $this->assertSame('new', $message->status->value);

        Mail::assertSent(ContactMessageReceived::class, function (ContactMessageReceived $mail) use ($message): bool {
            return $mail->hasTo(config('dukavo.marketing.contact_recipient') ?: config('mail.from.address'))
                && $mail->message->is($message);
        });
    }

    public function test_a_mail_transport_failure_does_not_lose_the_message(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP is down'));

        $this->postJson('/api/v1/public/contact', self::VALID)
            ->assertCreated()
            ->assertJsonPath('success', true);

        // The notification is a convenience; the stored row is the delivery.
        $this->assertDatabaseCount('contact_messages', 1);
    }

    public function test_the_honeypot_field_drops_a_bot_without_storing_or_sending(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/public/contact', self::VALID + [
            'website' => 'https://spam.example',
        ])->assertCreated();

        $this->assertDatabaseCount('contact_messages', 0);
        Mail::assertNothingSent();
    }

    public function test_carriage_returns_are_stripped_from_the_subject(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/public/contact', [
            'subject' => "Billing question\r\nBcc: attacker@example.test",
        ] + self::VALID)->assertCreated();

        $subject = ContactMessage::query()->firstOrFail()->subject;

        // A raw newline in the subject would become an extra header when the
        // mailable echoes it — header injection, defeated at the door.
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringContainsString('Billing question', $subject);
    }

    public function test_the_notification_escapes_html_from_the_sender(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/public/contact', [
            'message' => '<script>alert("xss")</script>',
        ] + self::VALID)->assertCreated();

        $rendered = (new ContactMessageReceived(ContactMessage::query()->firstOrFail()))->render();

        $this->assertStringNotContainsString('<script>alert', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_an_oversized_message_is_rejected(): void
    {
        $this->postJson('/api/v1/public/contact', [
            'message' => str_repeat('a', 5001),
        ] + self::VALID)->assertUnprocessable()->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_a_missing_field_is_rejected(): void
    {
        $this->postJson('/api/v1/public/contact', ['name' => 'No Email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'subject', 'message']);
    }

    public function test_the_form_is_rate_limited_per_ip(): void
    {
        Mail::fake();

        foreach (range(1, 4) as $index) {
            $this->postJson('/api/v1/public/contact', self::VALID + [
                'email' => "flood-{$index}@example.test",
            ]);
        }

        $this->postJson('/api/v1/public/contact', self::VALID)
            ->assertStatus(429);

        $this->assertDatabaseCount('contact_messages', 3);
    }
}
