<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BundleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bundle\PreviewBundleRequest;
use App\Http\Requests\Bundle\StoreBundleRequest;
use App\Http\Requests\Bundle\UpdateBundleRequest;
use App\Http\Resources\BundleResource;
use App\Models\Bundle;
use App\Services\Bundles\BundleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Merchant-curated bundles.
 *
 * Bundles use the product permissions: a merchant who may create or edit a
 * product already understands the catalogue, and introducing a parallel bundle
 * permission would make the common "manage my catalogue" role harder to grant
 * without adding a real boundary.
 */
class BundleController extends Controller
{
    public function __construct(private readonly BundleService $bundles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $bundles = Bundle::query()
            ->with($this->bundles->relations())
            ->when($request->filled('search'), fn ($query) => $query->where(function ($inner) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $inner->where('name', 'like', $term)->orWhere('description', 'like', $term);
            }))
            ->when(
                $request->filled('status') && BundleStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when($request->filled('store_id'), fn ($query) => $query->where(function ($inner) use ($request): void {
                $inner->where('store_id', $request->integer('store_id'))->orWhereNull('store_id');
            }))
            ->orderByDesc('updated_at')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return BundleResource::collection($bundles);
    }

    public function store(StoreBundleRequest $request): JsonResponse
    {
        $bundle = $this->bundles->create($request->validated());

        return ApiResponse::created('Bundle created.', new BundleResource($bundle));
    }

    public function show(Bundle $bundle): BundleResource
    {
        return new BundleResource($bundle->load($this->bundles->relations()));
    }

    public function update(UpdateBundleRequest $request, Bundle $bundle): BundleResource
    {
        return new BundleResource($this->bundles->update($bundle, $request->validated()));
    }

    public function destroy(Bundle $bundle): Response
    {
        $this->bundles->delete($bundle);

        return response()->noContent();
    }

    public function preview(PreviewBundleRequest $request, Bundle $bundle): JsonResponse
    {
        $preview = $this->bundles->preview(
            $bundle,
            $request->filled('store_id') ? $request->integer('store_id') : null,
            $request->filled('expected_catalog_fingerprint') ? $request->string('expected_catalog_fingerprint')->toString() : null,
        );

        return ApiResponse::success('', $preview);
    }
}
