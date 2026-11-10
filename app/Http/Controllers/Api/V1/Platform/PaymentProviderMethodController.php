<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Payments\PaymentProviderMethodRequest;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderMethod;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Provider source ids for a customer-facing method.
 *
 * The source id is server configuration: it is chosen from this table at
 * checkout and is never accepted from a browser.
 */
class PaymentProviderMethodController extends Controller
{
    public function store(PaymentProviderMethodRequest $request, PaymentProvider $provider): JsonResponse
    {
        $method = PaymentProviderMethod::query()->updateOrCreate(
            [
                'provider_id' => $provider->getKey(),
                'method_code' => $request->validated('method_code'),
                'country_code' => $request->validated('country_code'),
                'source_id' => $request->validated('source_id'),
            ],
            $request->safe()->except(['method_code', 'country_code', 'source_id']) + [
                'provider_id' => $provider->getKey(),
                'method_code' => $request->validated('method_code'),
                'country_code' => $request->validated('country_code'),
                'source_id' => $request->validated('source_id'),
            ],
        );

        return ApiResponse::created('Provider method saved.', $this->payload($method));
    }

    public function update(
        PaymentProviderMethodRequest $request,
        PaymentProvider $provider,
        PaymentProviderMethod $providerMethod,
    ): JsonResponse {
        abort_unless((int) $providerMethod->provider_id === (int) $provider->getKey(), 404);

        $providerMethod->fill($request->safe()->except(['method_code', 'country_code', 'source_id']))->save();

        return ApiResponse::success('Provider method updated.', $this->payload($providerMethod));
    }

    public function destroy(PaymentProvider $provider, PaymentProviderMethod $providerMethod): JsonResponse
    {
        abort_unless((int) $providerMethod->provider_id === (int) $provider->getKey(), 404);

        $providerMethod->delete();

        return ApiResponse::success('Provider method removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentProviderMethod $method): array
    {
        return [
            'id' => $method->id,
            'provider_id' => $method->provider_id,
            'method_code' => $method->method_code,
            'country_code' => $method->country_code,
            'source_id' => $method->source_id,
            'priority' => (int) $method->priority,
            'is_active' => (bool) $method->is_active,
            'config' => $method->config,
        ];
    }
}
