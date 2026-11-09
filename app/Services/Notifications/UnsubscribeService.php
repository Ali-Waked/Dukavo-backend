<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationConsent;
use App\Models\NotificationUnsubscribeToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Marketing consent and its secure unsubscribe links.
 *
 * A link is scoped to one account and one channel. Using it writes an
 * authoritative preference change and an append-only consent record; it never
 * touches security or operational mail. Tokens are hashed at rest and expire.
 */
final class UnsubscribeService
{
    public const string CATEGORY = 'product_news';

    /**
     * @return array{token: string, url: string, api_url: string, expires_at: string|null}
     */
    public function issue(User $user, string $channel = 'email', string $category = self::CATEGORY): array
    {
        $token = NotificationUnsubscribeToken::issue($user, $channel, $category);
        $plain = (string) $token->plainTextToken;

        return [
            'token' => $plain,
            'url' => $this->frontendUrl($plain),
            'api_url' => $this->apiUrl($plain),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }

    public function resolve(string $plain): ?NotificationUnsubscribeToken
    {
        return NotificationUnsubscribeToken::findByPlainText($plain);
    }

    /**
     * Apply the opt-out and keep an immutable record of it.
     *
     * @return array{user: User, channel: string, category: string}
     */
    public function unsubscribe(NotificationUnsubscribeToken $token, ?Request $request = null): array
    {
        $user = $token->user;

        if (! $user instanceof User) {
            abort(410, 'This unsubscribe link is no longer valid.');
        }

        $preferences = $user->notification_preferences ?? [];
        $preferences[$token->channel] ??= [];
        $preferences[$token->channel][$token->category] = false;

        $user->forceFill(['notification_preferences' => $preferences])->save();

        $this->record(
            user: $user,
            channel: $token->channel,
            category: $token->category,
            action: NotificationConsent::REVOKED,
            source: 'unsubscribe_link',
            request: $request,
            metadata: ['unsubscribe_token_id' => $token->getKey()],
        );

        $token->forceFill(['used_at' => now()])->save();

        return [
            'user' => $user,
            'channel' => $token->channel,
            'category' => $token->category,
        ];
    }

    /**
     * Record a consent decision made anywhere in the product.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?User $user,
        string $channel,
        string $category,
        string $action,
        string $source,
        ?Request $request = null,
        array $metadata = [],
    ): NotificationConsent {
        return NotificationConsent::query()->create([
            'user_id' => $user?->getKey(),
            'email' => $user?->email,
            'channel' => $channel,
            'category' => $category,
            'action' => $action,
            'source' => $source,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /** The human-facing confirmation page for a link in an email. */
    public function frontendUrl(string $plain): string
    {
        return rtrim((string) config('dukavo.urls.frontend'), '/').'/unsubscribe?token='.urlencode($plain);
    }

    /** The one-click POST target advertised to mailbox providers. */
    public function apiUrl(string $plain): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/unsubscribe/'.urlencode($plain);
    }
}
