<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\StoreStoreRequest;
use App\Http\Requests\Stores\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Stores\StoreService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Stores (outlets).
 *
 * `Store` carries a tenant scope, so the listing can only ever contain the
 * active tenant's stores.
 */
class StoreController extends Controller
{
    public function __construct(private readonly StoreService $stores) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $stores = Store::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->active())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return StoreResource::collection($stores);
    }

    public function store(StoreStoreRequest $request): JsonResponse
    {
        return ApiResponse::created('Store created.', new StoreResource($this->stores->create($request->validated())));
    }

    public function show(Store $store): StoreResource
    {
        return new StoreResource($store);
    }

    public function update(UpdateStoreRequest $request, Store $store): StoreResource
    {
        return new StoreResource($this->stores->update($store, $request->validated()));
    }

    public function destroy(Store $store): Response
    {
        $this->stores->delete($store);

        return response()->noContent();
    }

    /**
     * Promote a store to the tenant default. The service demotes the previous
     * one in the same transaction, so there is never more than one default.
     */
    public function makeDefault(Store $store): StoreResource
    {
        return new StoreResource($this->stores->setDefault($store));
    }
}
