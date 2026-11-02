<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StorefrontCheckoutRequest;
use App\Http\Resources\Storefront\PublicOrderResource;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Storefront\StorefrontCheckoutService;
use App\Services\Storefront\StorefrontService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Public checkout and order tracking.
 *
 * Both actions resolve the tenant and store from the URL, then run inside the
 * resolved tenant context. Tracking additionally requires the unguessable token
 * created at checkout; the order number alone is not enough.
 */
class StorefrontCheckoutController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StorefrontService $storefront,
        private readonly StorefrontCheckoutService $checkout,
    ) {}

    public function store(StorefrontCheckoutRequest $request, string $tenantSlug, string $storeSlug): JsonResponse
    {
        return $this->withStore($tenantSlug, $storeSlug, function (Store $store) use ($request): JsonResponse {
            $order = $this->checkout->checkout($store, $request->validated());

            return ApiResponse::created(
                'Order placed.',
                new PublicOrderResource($order->loadMissing(['items', 'store']), includeTrackingToken: true),
            );
        });
    }

    public function show(string $tenantSlug, string $storeSlug, string $token): JsonResponse
    {
        return $this->withStore($tenantSlug, $storeSlug, function (Store $store) use ($token): JsonResponse {
            $order = $this->checkout->findByTrackingToken($store, $token);

            return ApiResponse::success('', new PublicOrderResource($order));
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(Store): TReturn  $callback
     * @return TReturn
     */
    private function withStore(string $tenantSlug, string $storeSlug, callable $callback): mixed
    {
        $tenant = $this->storefront->resolveTenant($tenantSlug);

        return $this->tenants->run($tenant, function () use ($tenant, $storeSlug, $callback): mixed {
            return $callback($this->storefront->resolveStore($tenant, $storeSlug));
        });
    }
}
