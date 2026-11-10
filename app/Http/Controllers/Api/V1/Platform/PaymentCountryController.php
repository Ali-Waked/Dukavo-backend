<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Payments\UpdatePaymentCountryRequest;
use App\Models\PaymentCountry;
use App\Services\Payments\PaymentCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Platform control of the markets Dukavo sells into.
 *
 * The catalogue is data, so a market is enabled or paused without a deployment.
 * This controller never reads a merchant connection or credential.
 */
class PaymentCountryController extends Controller
{
    public function __construct(private readonly PaymentCatalogService $catalog) {}

    public function index(): JsonResponse
    {
        $countries = $this->catalog->countries()->load('providerCoverage.provider');

        return ApiResponse::success('', [
            'countries' => $countries->map(fn (PaymentCountry $country): array => [
                'id' => $country->id,
                'code' => $country->code,
                'name' => $country->name,
                'name_ar' => $country->name_ar,
                'currency' => $country->currency,
                'is_active' => (bool) $country->is_active,
                'sort_order' => (int) $country->sort_order,
                'coverage' => $country->providerCoverage->map(fn ($coverage): array => [
                    'id' => $coverage->id,
                    'provider_id' => $coverage->provider_id,
                    'provider_code' => $coverage->provider?->code,
                    'provider_name' => $coverage->provider?->name,
                    'settlement_currency' => $coverage->settlement_currency,
                    'merchant_onboarding' => (bool) $coverage->merchant_onboarding,
                    'transaction_capable' => (bool) $coverage->transaction_capable,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function update(UpdatePaymentCountryRequest $request, PaymentCountry $country): JsonResponse
    {
        $country->fill($request->validated())->save();

        return ApiResponse::success('Country updated.', [
            'country' => [
                'id' => $country->id,
                'code' => $country->code,
                'is_active' => (bool) $country->is_active,
                'sort_order' => (int) $country->sort_order,
            ],
        ]);
    }
}
