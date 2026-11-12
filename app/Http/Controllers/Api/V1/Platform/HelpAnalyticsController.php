<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleVisibility;
use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\HelpSearchLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Help Center analytics for the platform team.
 *
 * Every number here is a real aggregate over the article, feedback and search
 * tables — nothing is estimated, sampled or fabricated. The search reports are
 * bounded to a recent window (default 30 days) because an all-time popular
 * search list stops telling the team what readers need *now*; the raw logs are
 * pruned on a schedule anyway, so the window and the retention policy agree.
 *
 * The counts include drafts and archived articles where the question is
 * "what does the team maintain", and restrict to published articles where the
 * question is "what do readers do".
 */
class HelpAnalyticsController extends Controller
{
    private const SEARCH_WINDOW_DAYS = 30;

    /**
     * The headline numbers plus the two maintenance queues: categories with
     * nothing in them, and the articles the team most recently touched.
     */
    public function overview(Request $request): JsonResponse
    {
        $window = max(1, min(365, $request->integer('days', self::SEARCH_WINDOW_DAYS)));

        $statusCounts = HelpArticle::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $visibilityCounts = HelpArticle::query()
            ->selectRaw('visibility, count(*) as aggregate')
            ->groupBy('visibility')
            ->pluck('aggregate', 'visibility');

        $publishedViews = (int) HelpArticle::query()
            ->published()
            ->sum('view_count');

        $votes = HelpArticle::query()
            ->published()
            ->selectRaw('coalesce(sum(helpful_count), 0) as helpful, coalesce(sum(unhelpful_count), 0) as unhelpful')
            ->first();

        $helpful = (int) ($votes->helpful ?? 0);
        $unhelpful = (int) ($votes->unhelpful ?? 0);

        return ApiResponse::success(data: [
            'totals' => [
                'categories' => HelpCategory::query()->count(),
                'active_categories' => HelpCategory::query()->active()->count(),
                'articles' => HelpArticle::query()->count(),
                'published_articles' => HelpArticle::query()->published()->count(),
                'by_status' => [
                    'draft' => (int) ($statusCounts[HelpArticleStatus::Draft->value] ?? 0),
                    'in_review' => (int) ($statusCounts[HelpArticleStatus::InReview->value] ?? 0),
                    'published' => (int) ($statusCounts[HelpArticleStatus::Published->value] ?? 0),
                    'archived' => (int) ($statusCounts[HelpArticleStatus::Archived->value] ?? 0),
                ],
                'by_visibility' => [
                    'public' => (int) ($visibilityCounts[HelpArticleVisibility::Public->value] ?? 0),
                    'authenticated' => (int) ($visibilityCounts[HelpArticleVisibility::Authenticated->value] ?? 0),
                    'merchants' => (int) ($visibilityCounts[HelpArticleVisibility::Merchants->value] ?? 0),
                    'platform' => (int) ($visibilityCounts[HelpArticleVisibility::Platform->value] ?? 0),
                ],
                'published_views' => $publishedViews,
                'helpful_votes' => $helpful,
                'unhelpful_votes' => $unhelpful,
                'helpfulness_ratio' => $helpful + $unhelpful > 0
                    ? round($helpful / ($helpful + $unhelpful), 3)
                    : null,
                'searches_last_window' => HelpSearchLog::query()
                    ->where('created_at', '>=', now()->subDays($window))
                    ->count(),
            ],
            'empty_categories' => HelpCategory::query()
                ->ordered()
                ->whereDoesntHave('articles')
                ->get(['id', 'name', 'slug'])
                ->map(fn (HelpCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->all(),
            'recently_updated' => HelpArticle::query()
                ->with('category:id,name,slug')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(['id', 'title', 'slug', 'status', 'visibility', 'category_id', 'updated_at'])
                ->map(fn (HelpArticle $article): array => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'slug' => $article->slug,
                    'status' => $article->status,
                    'visibility' => $article->visibility,
                    'category' => $article->category?->name,
                    'updated_at' => $article->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * What readers search for, and what they cannot find. Popular searches
     * guide what to write next; zero-result searches say it loudest.
     */
    public function searches(Request $request): JsonResponse
    {
        $window = max(1, min(365, $request->integer('days', self::SEARCH_WINDOW_DAYS)));
        $since = now()->subDays($window);

        $popular = HelpSearchLog::query()
            ->where('created_at', '>=', $since)
            ->groupBy('term')
            ->orderByDesc('aggregate')
            ->limit(25)
            ->get(['term', DB::raw('count(*) as aggregate'), DB::raw('sum(results_count) as results')])
            ->map(fn (HelpSearchLog $log): array => [
                'term' => $log->term,
                'searches' => (int) $log->aggregate,
                'results' => (int) $log->results,
            ])
            ->all();

        $zeroResults = HelpSearchLog::query()
            ->where('created_at', '>=', $since)
            ->where('results_count', 0)
            ->groupBy('term')
            ->orderByDesc('aggregate')
            ->limit(25)
            ->get(['term', DB::raw('count(*) as aggregate')])
            ->map(fn (HelpSearchLog $log): array => [
                'term' => $log->term,
                'searches' => (int) $log->aggregate,
            ])
            ->all();

        return ApiResponse::success(data: [
            'days' => $window,
            'popular' => $popular,
            'zero_results' => $zeroResults,
        ]);
    }

    /**
     * How readers respond to the published guides: the most read articles,
     * and the ones readers mark unhelpful often enough to deserve a rewrite.
     */
    public function helpfulness(Request $request): JsonResponse
    {
        $limit = max(1, min(25, $request->integer('limit', 10)));

        $mostRead = HelpArticle::query()
            ->published()
            ->with('category:id,name,slug')
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'view_count', 'helpful_count', 'unhelpful_count', 'category_id'])
            ->map(fn (HelpArticle $article): array => $this->helpfulnessRow($article))
            ->all();

        // Least helpful first among articles that have at least three votes —
        // one unhappy reader is a comment, a pattern is a problem. The vote
        // sum is guaranteed >= 3 by the filter, so the ratio needs no zero
        // guard; the 100.0 multiplier keeps the division fractional on both
        // MySQL and SQLite (which would otherwise truncate integers to 0).
        $leastHelpful = HelpArticle::query()
            ->published()
            ->with('category:id,name,slug')
            ->whereRaw('(helpful_count + unhelpful_count) >= 3')
            ->orderByRaw('(helpful_count * 100.0 / (helpful_count + unhelpful_count)) asc')
            ->orderByDesc('unhelpful_count')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'view_count', 'helpful_count', 'unhelpful_count', 'category_id'])
            ->map(fn (HelpArticle $article): array => $this->helpfulnessRow($article))
            ->all();

        return ApiResponse::success(data: [
            'most_read' => $mostRead,
            'least_helpful' => $leastHelpful,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function helpfulnessRow(HelpArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'category' => $article->category?->name,
            'view_count' => (int) $article->view_count,
            'helpful_count' => (int) $article->helpful_count,
            'unhelpful_count' => (int) $article->unhelpful_count,
            'helpfulness' => $article->helpfulnessRatio(),
        ];
    }
}
