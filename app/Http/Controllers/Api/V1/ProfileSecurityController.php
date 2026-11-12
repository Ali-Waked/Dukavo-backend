<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\SecurityActivityRequest;
use App\Http\Resources\SecurityEventResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The account holder's own security history.
 *
 * Every query starts at `$request->user()->securityEvents()`, so a caller can
 * filter their own events but can never ask for someone else's. The table is
 * append-only: there is no endpoint to edit or delete a row.
 */
class ProfileSecurityController extends Controller
{
    public function activity(SecurityActivityRequest $request): JsonResponse
    {
        $perPage = (int) ($request->validated('per_page') ?? config('dukavo.security_events.per_page', 20));

        $query = $request->user()
            ->securityEvents()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('event')) {
            $query->where('event', SecurityEventType::from((string) $request->validated('event')));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->validated('status'));
        }

        return ApiResponse::success(
            'Security activity loaded.',
            SecurityEventResource::collection($query->paginate($perPage)),
        );
    }
}
