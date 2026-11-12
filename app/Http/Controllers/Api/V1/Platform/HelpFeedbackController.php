<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\HelpArticleFeedbackResource;
use App\Models\HelpArticleFeedback;
use App\Services\Help\HelpFeedbackService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Help Center feedback review.
 *
 * The platform team reads the "was this helpful?" answers to find guides that
 * confuse readers. The rows are immutable history; the only mutation is
 * deleting abuse, which the service reconciles with the article's counters.
 */
class HelpFeedbackController extends Controller
{
    public function __construct(private readonly HelpFeedbackService $feedback) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = HelpArticleFeedback::query()
            ->with(['article', 'user'])
            ->when($request->filled('article'), fn ($query) => $query
                ->where('article_id', $request->integer('article')))
            ->when($request->filled('helpful'), fn ($query) => $query
                ->where('helpful', $request->boolean('helpful')))
            ->when($request->filled('has_comment'), fn ($query) => $query
                ->whereNotNull('comment'))
            ->when($request->filled('search'), fn ($query) => $query
                ->where('comment', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return HelpArticleFeedbackResource::collection($rows);
    }

    public function destroy(HelpArticleFeedback $feedback): Response
    {
        $this->feedback->delete($feedback->loadMissing('article'));

        return response()->noContent();
    }

    /**
     * The comments only, for the "what did we get wrong" review queue.
     */
    public function comments(Request $request): JsonResponse
    {
        $rows = HelpArticleFeedback::query()
            ->with(['article', 'user'])
            ->whereNotNull('comment')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return ApiResponse::success(data: HelpArticleFeedbackResource::collection($rows)->resolve());
    }
}
