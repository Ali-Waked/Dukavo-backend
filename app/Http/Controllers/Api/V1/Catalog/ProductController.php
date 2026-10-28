<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The product catalogue.
 *
 * Products are tenant scoped, so the filters below can never reach another
 * merchant's rows. Sorting is restricted to an allow-list, which keeps a
 * query-string value from reaching the `ORDER BY` clause unchecked.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $products = Product::query()
            // An explicit status filter wins; otherwise the catalogue shows only
            // sellable products.
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
                fn ($query) => $query->active(),
            )
            ->when($request->filled('search'), fn ($query) => $query->search($request->string('search')->toString()))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            // A store's catalogue includes products shared with every store
            // (`store_id` null) plus products assigned specifically to it.
            ->when($request->filled('store_id'), fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('store_id', $request->integer('store_id'))
                    ->orWhereNull('store_id'),
            ))
            ->when($request->boolean('low_stock'), fn ($query) => $query->whereHas(
                'inventories',
                fn ($inner) => $inner->lowStock(),
            ))
            // `inventories` backs the aggregate `stock` block on every list row;
            // without it the catalogue renders a blank stock figure.
            ->with(['variants', 'category', 'store', 'inventories'])
            ->orderBy($this->sortColumn($request), $request->boolean('desc', true) ? 'desc' : 'asc')
            ->paginate($perPage)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        return ApiResponse::created('Product created.', new ProductResource($this->products->create($request->validated())));
    }

    /**
     * Live slug check for the product form.
     *
     * The tenant scope restricts the lookup to the active tenant, so another
     * merchant owning the same slug does not make it appear taken. Archived
     * products are included because the unique index spans them: a slug freed by
     * archiving is still reserved until the row is force deleted.
     */
    public function slugAvailability(Request $request): JsonResponse
    {
        $slug = Str::slug($request->string('slug')->toString());

        $available = $slug !== '' && ! Product::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($request->filled('ignore'), fn ($query) => $query->whereKeyNot($request->integer('ignore')))
            ->exists();

        return response()->json([
            'success' => true,
            'data' => ['slug' => $slug, 'available' => $available],
        ]);
    }

    public function show(Product $product): ProductResource
    {
        // `variants.inventories` is loaded so each variant carries its own
        // per-store stock figure; the product-level `inventories` still backs
        // the aggregate `stock` block. Without the nested eager load the variant
        // rows would render with a blank stock column.
        return new ProductResource($product->load([
            'variants.inventories',
            'images',
            'category',
            'store',
            'inventories',
        ]));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->products->update($product, $request->validated()));
    }

    /**
     * Archive a product by default. A product that has been ordered is never
     * hard deleted, so order history keeps a real row to point at.
     */
    public function destroy(Request $request, Product $product): Response
    {
        $this->products->delete($product, $request->boolean('force'));

        return response()->noContent();
    }

    /**
     * @return list<string>
     */
    private function sortColumn(Request $request): string
    {
        return match ($request->string('sort')->toString()) {
            'name' => 'name',
            'price' => 'price',
            'sku' => 'sku',
            'created_at' => 'created_at',
            default => 'created_at',
        };
    }
}
