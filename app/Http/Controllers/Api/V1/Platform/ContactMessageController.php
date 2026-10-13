<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateContactMessageRequest;
use App\Http\Resources\ContactMessageResource;
use App\Models\ContactMessage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Contact message inbox.
 *
 * Triage only: the status column is the workflow (new → read → replied →
 * closed), and it is the single field an operator may change — the body stays
 * a faithful copy of what the visitor wrote. Status changes are audited on the
 * model; the message text itself never enters the audit trail.
 */
class ContactMessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $messages = ContactMessage::query()
            ->when($request->filled('status'), fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('email', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('subject', 'like', '%'.$request->string('search')->toString().'%')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return ContactMessageResource::collection($messages);
    }

    public function update(UpdateContactMessageRequest $request, ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->update($request->validated());

        return ApiResponse::success('Contact message updated.', new ContactMessageResource($contactMessage));
    }

    public function destroy(ContactMessage $contactMessage): Response
    {
        $contactMessage->delete();

        return response()->noContent();
    }
}
