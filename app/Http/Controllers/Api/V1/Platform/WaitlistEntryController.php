<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateWaitlistEntryRequest;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\WaitlistEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Waitlist administration.
 *
 * Signups are immutable except for their status: what a visitor typed is the
 * record of the signup, and the email is an identity rather than an editable
 * field. Deletion exists purely as spam cleanup — there is no product data
 * hanging off a waitlist row.
 */
class WaitlistEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = WaitlistEntry::query()
            ->when($request->filled('status'), fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('email', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('name', 'like', '%'.$request->string('search')->toString().'%')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return WaitlistEntryResource::collection($entries);
    }

    public function update(UpdateWaitlistEntryRequest $request, WaitlistEntry $waitlistEntry): JsonResponse
    {
        $waitlistEntry->update($request->validated());

        return ApiResponse::success('Waitlist entry updated.', new WaitlistEntryResource($waitlistEntry));
    }

    public function destroy(WaitlistEntry $waitlistEntry): Response
    {
        $waitlistEntry->delete();

        return response()->noContent();
    }
}
