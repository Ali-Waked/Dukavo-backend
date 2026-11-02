<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreShippingZoneRequest;
use App\Http\Requests\Storefront\UpdateShippingZoneRequest;
use App\Http\Resources\Storefront\ShippingZoneResource;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Services\Storefront\ShippingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Delivery zones for one store.
 *
 * Both models carry a tenant scope. A zone is additionally checked against the
 * store in the URL, because a valid zone id from a sibling store would
 * otherwise be editable through the wrong store's screen.
 */
class ShippingZoneController extends Controller
{
    public function __construct(private readonly ShippingService $shipping) {}

    public function index(Store $store): AnonymousResourceCollection
    {
        return ShippingZoneResource::collection($this->shipping->forStore($store));
    }

    public function store(StoreShippingZoneRequest $request, Store $store): JsonResponse
    {
        return ApiResponse::created(
            'Delivery zone created.',
            new ShippingZoneResource($this->shipping->create($store, $request->validated())),
        );
    }

    public function update(UpdateShippingZoneRequest $request, Store $store, ShippingZone $shippingZone): ShippingZoneResource
    {
        $this->ensureBelongsToStore($store, $shippingZone);

        return new ShippingZoneResource($this->shipping->update($shippingZone, $request->validated()));
    }

    public function destroy(Store $store, ShippingZone $shippingZone): Response
    {
        $this->ensureBelongsToStore($store, $shippingZone);

        $this->shipping->delete($shippingZone);

        return response()->noContent();
    }

    private function ensureBelongsToStore(Store $store, ShippingZone $zone): void
    {
        abort_unless((int) $zone->store_id === (int) $store->getKey(), 404);
    }
}
