<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreContactMessageRequest;
use App\Services\Marketing\ContactService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public contact form.
 *
 * Always answers 201 with the same body: the visitor learns only that the
 * message was accepted, never whether an address, subject or stored row was
 * involved. Rate limited by IP via `throttle:contact`; the `website` honeypot
 * drops bots into the same response without storing or mailing anything.
 */
class ContactController extends Controller
{
    private const string ACKNOWLEDGED = 'Thanks for reaching out — our team will reply within two business days.';

    public function __construct(private readonly ContactService $contacts) {}

    public function store(StoreContactMessageRequest $request): JsonResponse
    {
        if ($request->filled('website')) {
            return ApiResponse::created(self::ACKNOWLEDGED);
        }

        $this->contacts->submit($request->safe()->all());

        return ApiResponse::created(self::ACKNOWLEDGED);
    }
}
