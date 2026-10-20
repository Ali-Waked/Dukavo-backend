<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email form of an in-app notification.
 *
 * The subject and body are already rendered and localized for the recipient
 * when the notification row was written, so this mailable does no translation
 * of its own — it simply carries the stored copy. The notification is read-only
 * here; a mail transport failure must never edit the notification a user sees.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification',
            with: [
                'notification' => $this->notification,
                'recipient' => $this->recipient,
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
