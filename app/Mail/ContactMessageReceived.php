<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The public contact form submission, delivered to the platform inbox.
 *
 * Deliberately not `ShouldQueue`: the deployment story has no queue worker, so
 * a queued notification would be dropped silently. The subject already had
 * newlines stripped in the form request, which is what keeps it safe to echo
 * into the mail header here.
 */
class ContactMessageReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ContactMessage $message,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contact form: '.$this->message->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.contact.received',
            with: [
                'message' => $this->message,
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
