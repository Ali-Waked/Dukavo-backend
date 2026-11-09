<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\Emails\EmailContentBuilder;
use App\Services\Emails\EmailTemplateService;
use App\Services\Notifications\UnsubscribeService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The email form of an in-app notification.
 *
 * The subject starts from the notification's stored title, but a platform
 * operator may override it per locale. The body is rebuilt by
 * {@see EmailContentBuilder} so it shows real tenant records. Marketing copy
 * carries a hashed one-click unsubscribe link and the matching provider
 * headers; operational mail never does.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    private ?string $unsubscribeUrl = null;

    private ?string $unsubscribeApiUrl = null;

    private bool $marketing = false;

    public function __construct(
        public readonly Notification $notification,
        public readonly User $recipient,
    ) {
        $locale = (string) ($recipient->locale ?: config('dukavo.locales.default', 'en'));

        $this->locale = in_array($locale, (array) config('dukavo.locales.supported', ['en', 'ar']), true)
            ? $locale
            : (string) config('dukavo.locales.default', 'en');

        $this->marketing = $this->isMarketing();

        if ($this->marketing) {
            $issued = app(UnsubscribeService::class)->issue($recipient, 'email', 'product_news');
            $this->unsubscribeUrl = $issued['url'];
            $this->unsubscribeApiUrl = $issued['api_url'];
        }
    }

    public function envelope(): Envelope
    {
        $subject = app(EmailTemplateService::class)->subject(
            key: $this->notification->event_key,
            locale: $this->locale,
            variables: $this->variables(),
            fallback: $this->notification->title,
        );

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $content = app(EmailContentBuilder::class)->build(
            notification: $this->notification,
            recipient: $this->recipient,
            unsubscribeUrl: $this->unsubscribeUrl,
            marketing: $this->marketing,
        );

        return new Content(
            html: 'emails.layout',
            text: 'emails.text',
            with: [
                'content' => $content,
            ],
        );
    }

    public function headers(): Headers
    {
        if (! $this->marketing || $this->unsubscribeApiUrl === null) {
            return new Headers;
        }

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$this->unsubscribeApiUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
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

    private function isMarketing(): bool
    {
        if (in_array($this->notification->event_key, [
            'tenant.announcement.product',
            'tenant.announcement.release',
        ], true)) {
            return true;
        }

        if ($this->notification->event_key !== 'platform.broadcast') {
            return false;
        }

        $campaignId = (int) (($this->notification->data['campaign_id'] ?? 0));

        if ($campaignId === 0) {
            return false;
        }

        return (bool) NotificationCampaign::query()
            ->whereKey($campaignId)
            ->value('is_marketing');
    }

    /**
     * @return array<string, string>
     */
    private function variables(): array
    {
        $variables = [];

        foreach ((array) $this->notification->data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $variables[(string) $key] = (string) $value;
            }
        }

        $variables['title'] = $this->notification->title;
        $variables['body'] = $this->notification->body;
        $variables['name'] = $this->recipient->name;
        $variables['workspace'] = (string) ($variables['tenant'] ?? '');

        return $variables;
    }
}
