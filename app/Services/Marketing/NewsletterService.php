<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\NewsletterStatus;
use App\Http\Requests\Marketing\SubscribeNewsletterRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Newsletter subscriptions from the public marketing site.
 *
 * Consent is a first class fact: subscribing writes `consented_at`, and
 * re-subscribing after an unsubscribe records fresh consent instead of reviving
 * the old one. There is no email provider yet, so nothing is ever assumed
 * verified and no mail leaves here — the row in `newsletter_subscribers` is the
 * record a future provider integration will read from.
 *
 * The public endpoints answer identically whether the address was new, was
 * already subscribed, or did not exist: callers treat every outcome the same,
 * so the API never discloses whether a given address is on the list.
 */
class NewsletterService
{
    /**
     * Record a subscription. `$attributes` is validated, already-normalised
     * input from {@see SubscribeNewsletterRequest}.
     */
    public function subscribe(array $attributes): NewsletterSubscriber
    {
        try {
            return NewsletterSubscriber::query()->create([
                'email' => $attributes['email'],
                'source' => $attributes['source'] ?? null,
                'status' => NewsletterStatus::Subscribed,
                'consented_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A lost race or a repeat: the database's unique index is the
            // arbiter, and the existing row is returned so the caller can
            // answer without knowing which case this was.
            return $this->reSubscribe((string) $attributes['email']);
        }
    }

    /**
     * Mark an address unsubscribed. Idempotent: an address that does not exist
     * or is already unsubscribed is left untouched, and the caller's response
     * is identical either way.
     */
    public function unsubscribe(string $email): ?NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->where('email', $email)->first();

        if (! $subscriber instanceof NewsletterSubscriber) {
            return null;
        }

        if ($subscriber->status === NewsletterStatus::Unsubscribed) {
            return $subscriber;
        }

        $subscriber->update([
            'status' => NewsletterStatus::Unsubscribed,
            'unsubscribed_at' => now(),
        ]);

        return $subscriber;
    }

    /**
     * The row behind a duplicate subscribe. An address that previously left the
     * list is re-activated with fresh consent; one that is still subscribed is
     * returned unchanged.
     */
    private function reSubscribe(string $email): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->where('email', $email)->firstOrFail();

        if ($subscriber->status === NewsletterStatus::Unsubscribed) {
            $subscriber->update([
                'status' => NewsletterStatus::Subscribed,
                'consented_at' => now(),
                'unsubscribed_at' => null,
            ]);
        }

        return $subscriber;
    }
}
