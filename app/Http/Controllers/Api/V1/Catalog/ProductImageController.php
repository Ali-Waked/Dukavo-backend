<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ReorderProductImageRequest;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Requests\Catalog\UpdateProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A product's image gallery.
 *
 * The product is addressed by slug to match the rest of the catalogue. The image
 * is a nested, tenant-scoped binding, and every action re-checks that the image
 * belongs to the product in the path — otherwise a valid image id from a
 * different product could be edited or deleted through the wrong URL.
 */
class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImageService $images) {}

    public function store(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        $created = $this->images->store(
            $product,
            $request->file('images'),
            $request->validated('alt_text') ?? [],
        );

        return ApiResponse::created('Images uploaded.', ProductImageResource::collection($created));
    }

    public function update(UpdateProductImageRequest $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->assertOwned($product, $image);

        return ApiResponse::success(
            'Image updated.',
            new ProductImageResource($this->images->update($image, $request->validated())),
        );
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        $this->assertOwned($product, $image);

        $this->images->delete($image);

        return ApiResponse::success('Image removed.');
    }

    public function reorder(ReorderProductImageRequest $request, Product $product): JsonResponse
    {
        $this->images->reorder($product, $request->validated('images'));

        return ApiResponse::success(
            'Images reordered.',
            ProductImageResource::collection($product->images()->get()),
        );
    }

    /**
     * Both the product and the image are tenant scoped, so this only forbids an
     * image of *another product in the same tenant* from being reached through
     * the wrong product URL.
     */
    private function assertOwned(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->getKey(), 404);
    }
}
