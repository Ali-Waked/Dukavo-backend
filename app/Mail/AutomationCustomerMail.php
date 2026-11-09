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
 * A customer-facing email authored by a merchant's automation.
 *
 * The subject and body are merchant-controlled text stored on the delivery row.
 * They are rendered through the same branded layout as notification email so
 * the automation does not introduce a second visual language.
 */
class AutomationCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly array $content,
        public readonly string $locale = 'en',
    ) {
        $this->locale = $locale;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.layout',
            text: 'emails.text',
            with: ['content' => $this->content],
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
