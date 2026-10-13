<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\JoinWaitlistRequest;
use App\Services\Marketing\WaitlistService;
use App\Support\ApiResponse;
use App\Support\Response;
use Illuminate\Http\JsonResponse;

/**
 * Waitlist signups from the marketing site.
 *
 * The response is byte-for-byte identical whether the address is new or
 * already on the list: telling the two apart would turn this public endpoint
 * into an email-enumeration oracle. Rate limited by IP via `throttle:waitlist`
 * (defined in the service provider), and the `website` honeypot silently
 * short-circuits bots into that same generic answer.
 */
class WaitlistController extends Controller
{
    private const string THANK_YOU = "You're on the list! We'll email you when an invite is ready.";

    public function __construct(private readonly WaitlistService $waitlist) {}

    public function store(JoinWaitlistRequest $request): JsonResponse
    {
        // Honeypot: invisible to humans, irresistible to form-filling bots.
        // Nothing is stored and nothing is emailed — the bot gets the same
        // 202 as everybody else and learns nothing from the difference.
        if ($request->filled('website')) {
            return ApiResponse::success(self::THANK_YOU, null, Response::ACCEPTED);
        }

        $this->waitlist->join($request->safe()->all());

        return ApiResponse::success(self::THANK_YOU, null, Response::ACCEPTED);
    }
}
