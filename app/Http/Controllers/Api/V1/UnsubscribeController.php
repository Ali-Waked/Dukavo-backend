<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Notifications\UnsubscribeService;
use App\Support\ApiResponse;
use App\Support\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public marketing unsubscribe links.
 *
 * The link in a product-announcement email is the one place an unauthenticated
 * person may change a stored preference, so the token — not a session — is the
 * whole authority. It is hashed at rest, scoped to one account, channel and
 * category, and expires. `GET` only reports what the link would do, so opening
 * it never changes anything; `POST` is both the confirmation button and the
 * mailbox provider's one-click target.
 *
 * Nothing here touches security or operational mail: only the optional category
 * the token was issued for can be switched off.
 */
class UnsubscribeController extends Controller
{
    public function __construct(private readonly UnsubscribeService $unsubscribe) {}

    public function show(string $token): JsonResponse
    {
        $record = $this->unsubscribe->resolve($token);

        if ($record === null) {
            return ApiResponse::error('This unsubscribe link is not valid.', Response::GONE);
        }

        $user = $record->user;

        return ApiResponse::success('', [
            'valid' => $record->isUsable(),
            'already_used' => $record->used_at !== null,
            'expired' => $record->expires_at !== null && $record->expires_at->isPast(),
            'channel' => $record->channel,
            'category' => $record->category,
            // A masked address lets the recipient recognise the account without
            // turning a leaked link into an enumeration tool.
            'email' => $this->mask($user?->email),
            'expires_at' => $record->expires_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $record = $this->unsubscribe->resolve($token);

        if ($record === null) {
            return ApiResponse::error('This unsubscribe link is not valid.', Response::GONE);
        }

        // A mailbox provider may replay the one-click POST. Repeating an opt-out
        // is harmless and must not turn a successful unsubscribe into an error,
        // but an expired link is still refused.
        if (! $record->isUsable()) {
            if ($record->used_at !== null) {
                return ApiResponse::success('You are already unsubscribed from these emails.');
            }

            return ApiResponse::error('This unsubscribe link has expired.', Response::GONE);
        }

        $result = $this->unsubscribe->unsubscribe($record, $request);

        return ApiResponse::success('You have been unsubscribed from product announcements.', [
            'channel' => $result['channel'],
            'category' => $result['category'],
        ]);
    }

    private function mask(?string $email): ?string
    {
        if ($email === null || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
