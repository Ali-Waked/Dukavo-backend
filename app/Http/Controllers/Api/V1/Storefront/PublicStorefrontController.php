<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\PublicCategoryResource;
use App\Http\Resources\Storefront\PublicProductResource;
use App\Http\Resources\Storefront\PublicShippingZoneResource;
use App\Http\Resources\Storefront\PublicStorefrontResource;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Storefront\PaymentService;
use App\Services\Storefront\ShippingService;
use App\Services\Storefront\StorefrontService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unauthenticated shopfront reads.
 *
 * The tenant is resolved from the URL slug and the callback then runs inside
 * {@see TenantContext::run()}, which is what keeps the normal tenant scope
 * active. A client cannot widen the query by sending a tenant or store id.
 */
class PublicStorefrontController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StorefrontService $storefront,
        private readonly ShippingService $shipping,
        private readonly PaymentService $payments,
    ) {}

    public function show(string $tenantSlug, string $storeSlug): JsonResource
    {
        return $this->withStore($tenantSlug, $storeSlug, fn (Store $store) => new PublicStorefrontResource(
            $store->load('tenant'),
        ));
    }

    public function categories(string $tenantSlug, string $storeSlug): AnonymousResourceCollection
    {
        return $this->withStore($tenantSlug, $storeSlug, fn (Store $store) => PublicCategoryResource::collection(
            $this->storefront->categories($store),
        ));
    }

    public function products(Request $request, string $tenantSlug, string $storeSlug): AnonymousResourceCollection
    {
        return $this->withStore($tenantSlug, $storeSlug, fn (Store $store) => PublicProductResource::collection(
            $this->storefront->products($store, [
                'search' => $request->filled('search') ? $request->string('search')->toString() : null,
                'category' => $request->filled('category') ? $request->string('category')->toString() : null,
                'sort' => $request->filled('sort') ? $request->string('sort')->toString() : null,
                'per_page' => $request->integer('per_page', 24),
            ]),
        ));
    }

    public function product(string $tenantSlug, string $storeSlug, string $productSlug): JsonResource
    {
        return $this->withStore(
            $tenantSlug,
            $storeSlug,
            fn (Store $store) => new PublicProductResource($this->storefront->product($store, $productSlug)),
        );
    }

    public function shippingZones(string $tenantSlug, string $storeSlug): AnonymousResourceCollection
    {
        return $this->withStore($tenantSlug, $storeSlug, fn (Store $store) => PublicShippingZoneResource::collection(
            $this->shipping->forStore($store, activeOnly: true),
        ));
    }

    public function paymentMethods(string $tenantSlug, string $storeSlug): JsonResponse
    {
        return $this->withStore($tenantSlug, $storeSlug, fn (Store $store) => ApiResponse::success('', [
            'methods' => $this->payments->methods($store),
        ]));
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
