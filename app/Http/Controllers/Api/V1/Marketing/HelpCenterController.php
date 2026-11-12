<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Help\SubmitHelpFeedbackRequest;
use App\Http\Resources\PublicHelpArticleResource;
use App\Http\Resources\PublicHelpArticleSummaryResource;
use App\Http\Resources\PublicHelpCategoryResource;
use App\Models\HelpArticleFeedback;
use App\Services\Help\HelpCenterService;
use App\Services\Help\HelpFeedbackService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public Help Center.
 *
 * The marketing-surface half of the reader API: what an anonymous visitor of
 * dukavo.com may read. Only articles with public visibility are returned —
 * the tier list comes from the viewer (null here), never from the request,
 * so a merchant-only guide cannot leak through a query parameter.
 *
 * The authenticated half lives in {@see \App\Http\Controllers\Api\V1\Help\HelpCenterController},
 * which shares the same services for signed-in readers.
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
            $this->help->categories($this->help->allowedVisibilities(null)),
        ));
    }

    public function articles(Request $request): AnonymousResourceCollection
    {
        $articles = $this->help->articles(
            $this->help->allowedVisibilities(null),
            $this->articleFilters($request),
            $request->integer('per_page', 15),
        );

        return PublicHelpArticleSummaryResource::collection($articles);
    }

    public function article(Request $request, string $slug): JsonResponse
    {
        $visibilities = $this->help->allowedVisibilities(null);

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
            $this->help->allowedVisibilities(null),
            null,
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
     * An anonymous "was this helpful?" answer. Throttled by IP because there is
     * no account to hold accountable; deduplicated by a salted IP fingerprint.
     */
    public function feedback(SubmitHelpFeedbackRequest $request, string $slug): JsonResponse
    {
        $article = $this->help->article($slug, $this->help->allowedVisibilities(null));

        if ($article === null) {
            abort(404);
        }

        $recorded = $this->feedback->record(
            $article,
            null,
            HelpArticleFeedback::hashIp($request->ip()),
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
