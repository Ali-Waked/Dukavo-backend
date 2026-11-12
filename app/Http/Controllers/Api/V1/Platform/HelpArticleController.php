<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\HelpArticleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreHelpArticleRequest;
use App\Http\Requests\Platform\UpdateHelpArticleRequest;
use App\Http\Resources\HelpArticleResource;
use App\Http\Resources\HelpArticleSummaryResource;
use App\Models\HelpArticle;
use App\Services\Help\HelpContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Help Center article administration.
 *
 * Platform-level content behind the `platform` middleware, matching FAQ and
 * newsletter administration. The list and detail reads are separate resources:
 * the table never needs the body. Editorial state changes (publish, unpublish,
 * archive, restore) are explicit actions so the audit trail records the
 * decision, not just a field edit.
 */
class HelpArticleController extends Controller
{
    public function __construct(private readonly HelpContentService $content) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $articles = HelpArticle::query()
            ->with('category')
            ->when($request->filled('status'), fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('visibility'), fn ($query) => $query
                ->where('visibility', $request->string('visibility')->toString()))
            ->when($request->filled('category'), fn ($query) => $query
                ->whereHas('category', fn ($category) => $category
                    ->where('slug', $request->string('category')->toString())))
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('title', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('slug', 'like', '%'.$request->string('search')->toString().'%')))
            ->ordered()
            ->orderByDesc('updated_at')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return HelpArticleSummaryResource::collection($articles);
    }

    public function store(StoreHelpArticleRequest $request): JsonResponse
    {
        $article = $this->content->create(
            $request->user(),
            Arr::except($request->validated(), ['cover_image']),
            $request->file('cover_image'),
        );

        return ApiResponse::created('Article created.', new HelpArticleResource(
            $article->load(['category', 'tags', 'relatedArticles', 'author']),
        ));
    }

    public function show(HelpArticle $helpArticle): HelpArticleResource
    {
        return new HelpArticleResource($helpArticle->load(['category', 'tags', 'relatedArticles', 'author']));
    }

    public function update(UpdateHelpArticleRequest $request, HelpArticle $helpArticle): JsonResponse
    {
        $article = $this->content->update(
            $helpArticle,
            Arr::except($request->validated(), ['cover_image', 'remove_cover']),
            $request->file('cover_image'),
            $request->boolean('remove_cover'),
        );

        return ApiResponse::success('Article updated.', new HelpArticleResource(
            $article->load(['category', 'tags', 'relatedArticles', 'author']),
        ));
    }

    public function destroy(HelpArticle $helpArticle): Response
    {
        $this->content->delete($helpArticle);

        return response()->noContent();
    }

    public function publish(Request $request, HelpArticle $helpArticle): JsonResponse
    {
        return ApiResponse::success('Article published.', new HelpArticleResource(
            $this->content->transition($helpArticle, HelpArticleStatus::Published)
                ->load(['category', 'tags', 'relatedArticles', 'author']),
        ));
    }

    public function unpublish(Request $request, HelpArticle $helpArticle): JsonResponse
    {
        return ApiResponse::success('Article unpublished.', new HelpArticleResource(
            $this->content->transition($helpArticle, HelpArticleStatus::Draft)
                ->load(['category', 'tags', 'relatedArticles', 'author']),
        ));
    }

    public function archive(Request $request, HelpArticle $helpArticle): JsonResponse
    {
        return ApiResponse::success('Article archived.', new HelpArticleResource(
            $this->content->transition($helpArticle, HelpArticleStatus::Archived)
                ->load(['category', 'tags', 'relatedArticles', 'author']),
        ));
    }

    public function restore(Request $request, HelpArticle $helpArticle): JsonResponse
    {
        // A restored article returns as a draft: an editor re-reads it before
        // it goes live again, rather than un-archiving straight to the public.
        return ApiResponse::success('Article restored as a draft.', new HelpArticleResource(
            $this->content->transition($helpArticle, HelpArticleStatus::Draft)
                ->load(['category', 'tags', 'relatedArticles', 'author']),
        ));
    }

    /**
     * Live slug check for the article form. Mirrors the newsletter form: the
     * client checks while the admin types, the server's unique rule remains the
     * final arbiter.
     */
    public function slugAvailability(Request $request): JsonResponse
    {
        $slug = Str::slug($request->string('slug')->toString());

        $available = $slug !== '' && ! HelpArticle::query()
            ->where('slug', $slug)
            ->when($request->filled('ignore'), fn ($query) => $query->whereKeyNot($request->integer('ignore')))
            ->exists();

        return response()->json([
            'success' => true,
            'data' => ['slug' => $slug, 'available' => $available],
        ]);
    }
}
