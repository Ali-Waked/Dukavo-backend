<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\UpdatePaymentSettingsRequest;
use App\Models\Store;
use App\Services\Storefront\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The payment methods a store may offer.
 *
 * Online payment is always reported with `available=false` until a provider is
 * configured; this endpoint must never imply a checkout that does not exist.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function show(Store $store): JsonResponse
    {
        return ApiResponse::success('', [
            'settings' => $this->payments->settings($store),
            'methods' => $this->payments->methods($store),
        ]);
    }

    public function update(UpdatePaymentSettingsRequest $request, Store $store): JsonResponse
    {
        $settings = array_replace($this->payments->settings($store), $request->validated());

        $store->forceFill(['payment_settings' => $settings])->save();

        return ApiResponse::success('Payment settings updated.', [
            'settings' => $this->payments->settings($store->refresh()),
            'methods' => $this->payments->methods($store),
        ]);
    }
}
