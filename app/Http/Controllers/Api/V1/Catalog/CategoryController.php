<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Catalog\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Catalogue categories.
 *
 * The tree endpoint nests children in memory rather than with a recursive query
 * so the whole navigation can be fetched in one request — the catalogue is small
 * per tenant and a merchant expects a single call to render their menu.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Nested category tree for navigation and select boxes.
     */
    public function tree(Request $request): JsonResponse
    {
        $tree = $this->categories->tree($request->boolean('include_inactive') === false);

        return response()->json([
            'success' => true,
            'data' => CategoryResource::collection(collect($tree)),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        return ApiResponse::created('Category created.', new CategoryResource($this->categories->create($request->validated())));
    }

    /**
     * Live slug check for the category form. The tenant scope keeps the answer
     * scoped to the active merchant; `ignore` lets an edit ignore its own row.
     */
    public function slugAvailability(Request $request): JsonResponse
    {
        $slug = Str::slug($request->string('slug')->toString());

        $available = $slug !== '' && ! Category::query()
            ->where('slug', $slug)
            ->when($request->filled('ignore'), fn ($query) => $query->whereKeyNot($request->integer('ignore')))
            ->exists();

        return response()->json([
            'success' => true,
            'data' => ['slug' => $slug, 'available' => $available],
        ]);
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category->load('parent'));
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        return new CategoryResource($this->categories->update($category, $request->validated()));
    }

    public function destroy(Category $category): Response
    {
        $this->categories->delete($category);

        return response()->noContent();
    }
}
