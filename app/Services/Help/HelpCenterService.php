<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Enums\HelpArticleVisibility;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\HelpSearchLog;
use App\Models\User;
use App\Services\Configuration\FeatureFlagService;
use App\Services\Rbac\PermissionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The reader side of the Help Center: who may see what, and how search works.
 *
 * Visibility is computed here — never taken from the request — so the public
 * surface, the authenticated surface and the search endpoint all agree on the
 * same rule. The tiers are cumulative from the reader's point of view:
 *
 *   guest             public
 *   authenticated      public, authenticated
 *   merchant member    public, authenticated, merchants
 *   platform operator  everything
 */
class HelpCenterService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly FeatureFlagService $features,
    ) {}

    /**
     * The visibility tiers one viewer is allowed to read.
     *
     * @return list<HelpArticleVisibility>
     */
    public function allowedVisibilities(?User $viewer): array
    {
        if ($viewer === null) {
            return [HelpArticleVisibility::Public];
        }

        if ($this->permissions->isPlatformOperator($viewer)) {
            return [
                HelpArticleVisibility::Public,
                HelpArticleVisibility::Authenticated,
                HelpArticleVisibility::Merchants,
                HelpArticleVisibility::Platform,
            ];
        }

        if ($this->permissions->tenantIdsFor($viewer) !== []) {
            return [
                HelpArticleVisibility::Public,
                HelpArticleVisibility::Authenticated,
                HelpArticleVisibility::Merchants,
            ];
        }

        return [
            HelpArticleVisibility::Public,
            HelpArticleVisibility::Authenticated,
        ];
    }

    /**
     * Every published article the viewer may read, in the reader order.
     *
     * @param  list<HelpArticleVisibility>  $visibilities
     * @param  array{category?: string, tag?: string, featured?: bool, popular?: bool}  $filters
     */
    public function articles(array $visibilities, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        if ($this->features->disabled('help_center')) {
            return new LengthAwarePaginator([], 0, min($perPage, 50));
        }

        return HelpArticle::query()
            ->published()
            ->visibleTo($visibilities)
            ->with(['category', 'tags'])
            ->when(isset($filters['category']), fn ($query) => $query
                ->whereHas('category', fn ($category) => $category
                    ->where('slug', $filters['category'])))
            ->when(isset($filters['tag']), fn ($query) => $query
                ->whereHas('tags', fn ($tag) => $tag->where('slug', $filters['tag'])))
            ->when($filters['featured'] ?? false, fn ($query) => $query->where('is_featured', true))
            ->when($filters['popular'] ?? false, fn ($query) => $query
                ->orderByDesc('view_count')->orderByDesc('id'),
                fn ($query) => $query->ordered(),
            )
            ->paginate(min($perPage, 50))
            ->withQueryString();
    }

    /**
     * One article by any of its reader-facing slugs, or null when it does not
     * exist or the viewer may not read it. A restricted article is
     * indistinguishable from a missing one — the honest answer to a guessed
     * URL is 404, not "forbidden, try harder".
     *
     * @param  list<HelpArticleVisibility>  $visibilities
     */
    public function article(string $slug, array $visibilities): ?HelpArticle
    {
        if ($this->features->disabled('help_center')) {
            return null;
        }

        return HelpArticle::query()
            ->published()
            ->visibleTo($visibilities)
            ->with(['category', 'tags', 'author'])
            ->forSlug($slug)
            ->first();
    }

    /**
     * The article's neighbours in its category's reader order, for prev/next
     * navigation. Both respect the same visibility set as the article itself,
     * so a hidden article is skipped rather than offered as a link.
     *
     * @param  list<HelpArticleVisibility>  $visibilities
     * @return array{previous: ?HelpArticle, next: ?HelpArticle}
     */
    public function neighbours(HelpArticle $article, array $visibilities): array
    {
        if ($this->features->disabled('help_center')) {
            return ['previous' => null, 'next' => null];
        }

        $siblings = HelpArticle::query()
            ->published()
            ->visibleTo($visibilities)
            ->where('category_id', $article->category_id)
            ->ordered()
            ->get(['id', 'slug', 'title', 'title_ar']);

        $position = $siblings->search(static fn (HelpArticle $row): bool => $row->is($article));

        if ($position === false) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $siblings->get($position - 1),
            'next' => $siblings->get($position + 1),
        ];
    }

    /**
     * The categories the viewer may browse, each with the number of articles
     * they may read inside it, as an `articles_count` attribute the reader
     * resources can pick up directly.
     *
     * @param  list<HelpArticleVisibility>  $visibilities
     * @return Collection<int, HelpCategory>
     */
    public function categories(array $visibilities): Collection
    {
        if ($this->features->disabled('help_center')) {
            return collect();
        }

        return HelpCategory::query()
            ->active()
            ->ordered()
            ->withCount(['articles' => fn ($query) => $query
                ->published()
                ->visibleTo($visibilities)])
            ->get();
    }

    /**
     * Search across titles, excerpts, bodies, tag names and approved ledger
     * translations, in both published languages, and record what was searched
     * for so the platform can see what readers cannot find.
     *
     * @param  list<HelpArticleVisibility>  $visibilities
     * @return array{articles: LengthAwarePaginator<HelpArticle>, categories: Collection<int, HelpCategory>}
     */
    public function search(string $term, array $visibilities, ?User $viewer, string $locale = 'en', int $perPage = 10): array
    {
        if ($this->features->disabled('help_center')) {
            return [
                'articles' => new LengthAwarePaginator([], 0, min($perPage, 20)),
                'categories' => collect(),
            ];
        }

        $needle = $this->escapeLike($term);
        $like = '%'.$needle.'%';

        $articles = HelpArticle::query()
            ->published()
            ->visibleTo($visibilities)
            ->with(['category', 'tags'])
            ->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('title_ar', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('excerpt_ar', 'like', $like)
                    ->orWhere('body', 'like', $like)
                    ->orWhere('body_ar', 'like', $like)
                    ->orWhereHas('tags', fn ($tag) => $tag
                        ->where('name', 'like', $like)
                        ->orWhere('name_ar', 'like', $like))
                    ->orWhereHas('translations', fn ($translation) => $translation
                        ->whereNotNull('published_text')
                        ->where('published_text', 'like', $like));
            })
            ->ordered()
            ->paginate(min($perPage, 20))
            ->withQueryString();

        $categories = HelpCategory::query()
            ->active()
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('name_ar', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('description_ar', 'like', $like);
            })
            ->ordered()
            ->withCount(['articles' => fn ($query) => $query
                ->published()
                ->visibleTo($visibilities)])
            ->get();

        $this->logSearch($term, $articles->total(), $viewer, $locale);

        return ['articles' => $articles, 'categories' => $categories];
    }

    /**
     * Record one search, unless it is empty or a stray long paste. Recording
     * happens after the query so the count reflects what the reader saw.
     */
    private function logSearch(string $term, int $results, ?User $viewer, string $locale): void
    {
        $normalised = HelpSearchLog::normaliseTerm($term);

        if ($normalised === '') {
            return;
        }

        HelpSearchLog::query()->create([
            'term' => $normalised,
            'locale' => strtolower(trim($locale)) ?: 'en',
            'results_count' => $results,
            'user_id' => $viewer?->getKey(),
        ]);
    }

    /** Keep LIKE wildcards from a typed term acting as wildcards. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
