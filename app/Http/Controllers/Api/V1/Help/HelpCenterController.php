<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Help;

use App\Http\Controllers\Controller;
use App\Http\Requests\Help\SubmitHelpFeedbackRequest;
use App\Http\Resources\PublicHelpArticleResource;
use App\Http\Resources\PublicHelpArticleSummaryResource;
use App\Http\Resources\PublicHelpCategoryResource;
use App\Models\User;
use App\Services\Help\HelpCenterService;
use App\Services\Help\HelpFeedbackService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Help Center for signed-in readers.
 *
 * Authenticated, active, and deliberately outside the `tenant` group: help
 * articles document Dukavo itself, not one workspace, so the same surface
 * serves every reader regardless of which tenant is selected. Visibility is
 * computed from the viewer — a merchant member sees merchant-only guides, a
 * platform operator sees everything — and never from the request.
 */
class HelpCenterController extends Controller
{
    public function __construct(
        private readonly HelpCenterService $help,
        private readonly HelpFeedbackService $feedback,
    ) {}

    public function categories(Request $request): JsonResponse
    {
        return ApiResponse::success(data: PublicHelpCategoryResource::collection(
            $this->help->categories($this->help->allowedVisibilities($request->user())),
        ));
    }

    public function articles(Request $request): AnonymousResourceCollection
    {
        $articles = $this->help->articles(
            $this->help->allowedVisibilities($request->user()),
            $this->articleFilters($request),
            $request->integer('per_page', 15),
        );

        return PublicHelpArticleSummaryResource::collection($articles);
    }

    public function article(Request $request, string $slug): JsonResponse
    {
        $visibilities = $this->help->allowedVisibilities($request->user());

        $article = $this->help->article($slug, $visibilities);

        if ($article === null) {
            abort(404);
        }

        $article->increment('view_count');

        return ApiResponse::success(data: new PublicHelpArticleResource(
            $article->load(['relatedArticles' => fn ($query) => $query
                ->published()
                ->visibleTo($visibilities)
                ->ordered()]),
            $this->help->neighbours($article, $visibilities),
        ));
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return ApiResponse::success(data: [
                'articles' => [],
                'categories' => [],
                'query' => '',
            ]);
        }

        $result = $this->help->search(
            $term,
            $this->help->allowedVisibilities($request->user()),
            $request->user(),
            (string) $request->query('locale', 'en'),
            $request->integer('per_page', 10),
        );

        return ApiResponse::success(data: [
            'query' => $term,
            'articles' => PublicHelpArticleSummaryResource::collection($result['articles'])->resolve(),
            'categories' => PublicHelpCategoryResource::collection($result['categories'])->resolve(),
        ]);
    }

    /**
     * A signed-in "was this helpful?" answer. Deduplicated by account, so the
     * same person cannot stack a vote from several devices.
     */
    public function feedback(SubmitHelpFeedbackRequest $request, string $slug): JsonResponse
    {
        $article = $this->help->article($slug, $this->help->allowedVisibilities($request->user()));

        if ($article === null) {
            abort(404);
        }

        /** @var User $viewer */
        $viewer = $request->user();

        $recorded = $this->feedback->record(
            $article,
            $viewer,
            null,
            $request->boolean('helpful'),
            $request->string('comment')->limit(1000)->toString(),
        );

        return $recorded['created']
            ? ApiResponse::created('Thank you for the feedback.')
            : ApiResponse::success('You have already told us about this article.');
    }

    /**
     * @return array{category?: string, tag?: string, featured?: bool, popular?: bool}
     */
    private function articleFilters(Request $request): array
    {
        return [
            'category' => $request->filled('category') ? $request->string('category')->toString() : null,
            'tag' => $request->filled('tag') ? $request->string('tag')->toString() : null,
            'featured' => $request->boolean('featured'),
            'popular' => $request->boolean('popular'),
        ];
    }
}
