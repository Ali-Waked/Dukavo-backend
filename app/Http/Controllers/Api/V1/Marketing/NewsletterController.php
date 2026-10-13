<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\SubscribeNewsletterRequest;
use App\Http\Requests\Marketing\UnsubscribeNewsletterRequest;
use App\Services\Marketing\NewsletterService;
use App\Support\ApiResponse;
use App\Support\Response;
use Illuminate\Http\JsonResponse;

/**
 * Newsletter management from the marketing site.
 *
 * Both endpoints answer with the same body regardless of the stored state:
 * a visitor learns only that their request was accepted, never whether the
 * address was known, new or already on the list — that difference would turn
 * these endpoints into an email enumeration oracle. Rate limited by IP via
 * `throttle:newsletter` (defined in the service provider), and the `website`
 * honeypot drops form-filling bots into the same generic answer.
 */
class NewsletterController extends Controller
{
    private const string SUBSCRIBED = "You're on the list! We'll email you when there's news worth sharing.";

    private const string UNSUBSCRIBED = "You've been unsubscribed from Dukavo emails.";

    public function __construct(private readonly NewsletterService $newsletters) {}

    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        // Honeypot: invisible to humans, irresistible to form-filling bots.
        // Nothing is stored — the bot gets the same 202 as everybody else.
        if ($request->filled('website')) {
            return ApiResponse::success(self::SUBSCRIBED, null, Response::ACCEPTED);
        }

        $this->newsletters->subscribe($request->safe()->all());

        return ApiResponse::success(self::SUBSCRIBED, null, Response::ACCEPTED);
    }

    public function unsubscribe(UnsubscribeNewsletterRequest $request): JsonResponse
    {
        if ($request->filled('website')) {
            return ApiResponse::success(self::UNSUBSCRIBED);
        }

        $this->newsletters->unsubscribe($request->safe()->string('email')->toString());

        return ApiResponse::success(self::UNSUBSCRIBED);
    }
}
