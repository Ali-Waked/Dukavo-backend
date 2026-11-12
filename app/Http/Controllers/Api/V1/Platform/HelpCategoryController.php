<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreHelpCategoryRequest;
use App\Http\Requests\Platform\UpdateHelpCategoryRequest;
use App\Http\Resources\HelpCategoryResource;
use App\Models\HelpCategory;
use App\Services\Help\HelpContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Help Center category administration.
 *
 * Platform-level content, so the routes sit behind the `platform` middleware
 * and outside any tenant context — the same choice as FAQ entries and
 * newsletter editions. A category is archived (`is_active = false`) rather
 * than deleted once it holds articles, which the service and the database
 * constraint both enforce.
 */
class HelpCategoryController extends Controller
{
    public function __construct(private readonly HelpContentService $content) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = HelpCategory::query()
            ->withCount('articles')
            ->when($request->filled('search'), fn ($query) => $query
                ->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('active'), fn ($query) => $query
                ->where('is_active', $request->boolean('active')))
            ->ordered()
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return HelpCategoryResource::collection($categories);
    }

    public function store(StoreHelpCategoryRequest $request): JsonResponse
    {
        $category = HelpCategory::query()->create($request->safe()->all());

        return ApiResponse::created('Category created.', new HelpCategoryResource($category->loadCount('articles')));
    }

    public function show(HelpCategory $helpCategory): HelpCategoryResource
    {
        return new HelpCategoryResource($helpCategory->loadCount('articles'));
    }

    public function update(UpdateHelpCategoryRequest $request, HelpCategory $helpCategory): JsonResponse
    {
        $helpCategory->fill($request->safe()->all())->save();

        return ApiResponse::success('Category updated.', new HelpCategoryResource($helpCategory->loadCount('articles')));
    }

    public function destroy(HelpCategory $helpCategory): Response
    {
        $this->content->deleteCategory($helpCategory);

        return response()->noContent();
    }

    /**
     * Live slug check for the category form, mirroring the product and
     * newsletter forms: the client checks while the admin types so a taken URL
     * is caught before submission, and the server's unique rule remains the
     * final arbiter.
     */
    public function slugAvailability(Request $request): JsonResponse
    {
        $slug = Str::slug($request->string('slug')->toString());

        $available = $slug !== '' && ! HelpCategory::query()
            ->where('slug', $slug)
            ->when($request->filled('ignore'), fn ($query) => $query->whereKeyNot($request->integer('ignore')))
            ->exists();

        return response()->json([
            'success' => true,
            'data' => ['slug' => $slug, 'available' => $available],
        ]);
    }

    /**
     * Reorder the whole category list from its ids, in the order they are
     * sent. `sort_order` is the position, so a drag-and-drop in the admin list
     * becomes one request rather than one per row.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer', 'exists:help_categories,id'],
        ]);

        foreach (array_values($validated['ids']) as $position => $id) {
            HelpCategory::query()->whereKey((int) $id)->update(['sort_order' => $position]);
        }

        return ApiResponse::success('Order saved.', HelpCategoryResource::collection(
            HelpCategory::query()->withCount('articles')->ordered()->get(),
        ));
    }
}
