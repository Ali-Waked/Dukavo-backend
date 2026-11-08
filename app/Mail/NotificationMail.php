<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use App\Services\Emails\EmailContentBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email form of an in-app notification.
 *
 * The in-app title and body were already localized when the notification was
 * written, so the subject is taken from that stored copy. The body, however, is
 * rebuilt for email: {@see EmailContentBuilder} turns the event and its real
 * records into the branded HTML structure and a plain-text alternative.
 *
 * The notification is read-only here; a mail transport failure must never edit
 * the notification a user sees.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        public readonly User $recipient,
    ) {
        $locale = (string) ($recipient->locale ?: config('dukavo.locales.default', 'en'));

        $this->locale = in_array($locale, (array) config('dukavo.locales.supported', ['en', 'ar']), true)
            ? $locale
            : (string) config('dukavo.locales.default', 'en');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->title,
        );
    }

    public function content(): Content
    {
        $content = app(EmailContentBuilder::class)->build($this->notification, $this->recipient);

        return new Content(
            html: 'emails.layout',
            text: 'emails.text',
            with: [
                'content' => $content,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
