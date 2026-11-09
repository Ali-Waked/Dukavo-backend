<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Services\Emails\EmailContentBuilder;
use App\Services\Emails\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;

/**
 * A transactional account or security message.
 *
 * These emails are not driven by notification preferences: a password reset, a
 * verification link or a new-device warning must reach the account holder even
 * if every optional switch is off. They use the same branded layout as
 * operational notifications, and the recipient's own language decides RTL/LTR.
 */
class AccountEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly string $template,
        public readonly array $data = [],
        public readonly ?string $actionUrl = null,
    ) {
        $locale = (string) ($recipient->locale ?: config('dukavo.locales.default', 'en'));

        $this->locale = in_array($locale, (array) config('dukavo.locales.supported', ['en', 'ar']), true)
            ? $locale
            : (string) config('dukavo.locales.default', 'en');
    }

    public function envelope(): Envelope
    {
        $key = 'email.account.'.$this->template.'_heading';
        $variables = $this->scalarData();

        $fallback = Lang::has($key)
            ? (string) Lang::get($key, $variables, $this->locale)
            : (string) config('dukavo.name', 'Dukavo');

        $subject = app(EmailTemplateService::class)->subject(
            key: 'account.'.$this->template,
            locale: $this->locale,
            variables: $variables,
            fallback: $fallback,
        );

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $content = app(EmailContentBuilder::class)->buildAccount(
            template: $this->template,
            locale: $this->locale,
            data: $this->data,
            actionUrl: $this->actionUrl,
        );

        return new Content(
            html: 'emails.layout',
            text: 'emails.text',
            with: ['content' => $content],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    private function scalarData(): array
    {
        $strings = [];

        foreach ($this->data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $strings[(string) $key] = (string) $value;
            }
        }

        return $strings;
    }
}
