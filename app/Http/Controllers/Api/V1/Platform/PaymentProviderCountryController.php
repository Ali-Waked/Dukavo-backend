<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Payments\PaymentProviderCountryRequest;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCountry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Documented provider coverage. Onboarding and transaction capability stay
 * separate so an operator cannot accidentally invite new merchants into a
 * market a provider has closed while still recording that existing merchants
 * can transact there.
 */
class PaymentProviderCountryController extends Controller
{
    public function store(PaymentProviderCountryRequest $request, PaymentProvider $provider): JsonResponse
    {
        $coverage = PaymentProviderCountry::query()->updateOrCreate(
            [
                'provider_id' => $provider->getKey(),
                'country_code' => strtoupper((string) $request->validated('country_code')),
            ],
            $request->safe()->except('country_code') + [
                'provider_id' => $provider->getKey(),
                'country_code' => strtoupper((string) $request->validated('country_code')),
            ],
        );

        return ApiResponse::created('Coverage saved.', $this->payload($coverage));
    }

    public function update(
        PaymentProviderCountryRequest $request,
        PaymentProvider $provider,
        PaymentProviderCountry $coverage,
    ): JsonResponse {
        abort_unless((int) $coverage->provider_id === (int) $provider->getKey(), 404);

        $coverage->fill($request->safe()->except('country_code'))->save();

        return ApiResponse::success('Coverage updated.', $this->payload($coverage));
    }

    public function destroy(PaymentProvider $provider, PaymentProviderCountry $coverage): JsonResponse
    {
        abort_unless((int) $coverage->provider_id === (int) $provider->getKey(), 404);

        $coverage->delete();

        return ApiResponse::success('Coverage removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentProviderCountry $coverage): array
    {
        return [
            'id' => $coverage->id,
            'provider_id' => $coverage->provider_id,
            'country_code' => $coverage->country_code,
            'settlement_currency' => $coverage->settlement_currency,
            'merchant_onboarding' => (bool) $coverage->merchant_onboarding,
            'transaction_capable' => (bool) $coverage->transaction_capable,
            'source_url' => $coverage->source_url,
            'notes' => $coverage->notes,
            'verified_at' => $coverage->verified_at?->toIso8601String(),
        ];
    }
}
