<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateNewsletterSubscriberRequest;
use App\Http\Resources\NewsletterSubscriberResource;
use App\Models\NewsletterSubscriber;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Newsletter administration.
 *
 * The list is status-only like the waitlist: subscription consent is anchored
 * to the timestamp the flow recorded, so the email and both consent timestamps
 * are read-only and the status column is the single writable field. Deletion
 * exists purely as spam or GDPR cleanup — nothing else references a row.
 */
class NewsletterSubscriberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $subscribers = NewsletterSubscriber::query()
            ->when($request->filled('status'), fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('email', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('source', 'like', '%'.$request->string('search')->toString().'%')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return NewsletterSubscriberResource::collection($subscribers);
    }

    public function update(UpdateNewsletterSubscriberRequest $request, NewsletterSubscriber $newsletterSubscriber): JsonResponse
    {
        $newsletterSubscriber->update($request->validated());

        return ApiResponse::success('Newsletter subscriber updated.', new NewsletterSubscriberResource($newsletterSubscriber));
    }

    public function destroy(NewsletterSubscriber $newsletterSubscriber): Response
    {
        $newsletterSubscriber->delete();

        return response()->noContent();
    }
}
