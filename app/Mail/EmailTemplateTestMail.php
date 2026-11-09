<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A platform operator's test copy of an email template.
 *
 * This is a real send through the configured mailer and is deliberately not
 * queued, so the operator gets the transport's answer immediately. The content
 * was rendered from the same builder and layout as a production email; the
 * recipient address is the only thing that differs.
 */
class EmailTemplateTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Test] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.raw',
            text: 'emails.raw-text',
            with: [
                'html' => $this->bodyHtml,
                'text' => $this->bodyText,
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
