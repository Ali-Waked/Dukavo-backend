<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StorePaymentConnectionRequest;
use App\Http\Resources\Payments\PaymentConnectionResource;
use App\Models\PaymentProvider;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Services\Payments\PaymentConnectionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A merchant's payment connections for one store.
 *
 * Credentials enter through this controller, are verified by the provider and
 * never come back out. Platform operators have no route that reads them.
 */
class PaymentConnectionController extends Controller
{
    public function __construct(private readonly PaymentConnectionService $connections) {}

    public function index(Store $store): JsonResponse
    {
        $connections = StorePaymentAccount::query()
            ->where('store_id', $store->getKey())
            ->with('provider')
            ->orderBy('provider_id')
            ->orderBy('environment')
            ->get();

        return ApiResponse::success('', [
            'connections' => PaymentConnectionResource::collection($connections)->resolve(),
            'providers' => $this->providers($store),
        ]);
    }

    public function store(StorePaymentConnectionRequest $request, Store $store): JsonResponse
    {
        $provider = PaymentProvider::query()
            ->where('code', strtolower((string) $request->validated('provider_code')))
            ->firstOrFail();

        $account = $this->connections->connect(
            $store,
            $provider,
            (array) $request->validated('credentials'),
            (string) $request->validated('environment'),
            $request->validated('webhook_secret'),
            $request->validated('merchant_reference'),
        );

        $account->loadMissing('provider');

        return ApiResponse::created('Payment connection saved.', new PaymentConnectionResource($account));
    }

    public function update(
        StorePaymentConnectionRequest $request,
        Store $store,
        StorePaymentAccount $account,
    ): JsonResponse {
        $provider = PaymentProvider::query()
            ->where('code', strtolower((string) $request->validated('provider_code')))
            ->firstOrFail();

        if ((int) $provider->getKey() !== (int) $account->provider_id) {
            throw BusinessRuleViolation::because('A connection cannot be moved to a different provider.');
        }

        $account = $this->connections->connect(
            $store,
            $provider,
            (array) $request->validated('credentials'),
            (string) $request->validated('environment'),
            $request->validated('webhook_secret'),
            $request->validated('merchant_reference'),
        );

        $account->loadMissing('provider');

        return ApiResponse::success('Payment connection updated.', new PaymentConnectionResource($account));
    }

    public function verify(Store $store, StorePaymentAccount $account): JsonResponse
    {
        $account = $this->connections->verify($account);
        $account->loadMissing('provider');

        return ApiResponse::success(
            $account->isActive() ? 'Payment connection verified.' : 'The provider rejected these credentials.',
            new PaymentConnectionResource($account),
        );
    }

    public function destroy(Store $store, StorePaymentAccount $account): JsonResponse
    {
        $this->connections->revoke($account);

        return ApiResponse::success('Payment connection revoked.');
    }

    /**
     * Providers a merchant can connect, with their documented coverage and
     * methods. This is catalogue data only; it contains no merchant secret.
     *
     * @return list<array<string, mixed>>
     */
    private function providers(Store $store): array
    {
        $countryCode = strtoupper(trim((string) $store->country));

        return PaymentProvider::query()
            ->where('status', 'active')
            ->where('integration_enabled', true)
            ->with(['countries', 'capabilities', 'methods.method'])
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentProvider $provider): array => [
                'code' => $provider->code,
                'name' => $provider->name,
                'docs_url' => $provider->docs_url,
                'webhook_docs_url' => $provider->webhook_docs_url,
                'notes' => $provider->notes,
                'capabilities' => $provider->capabilities->pluck('capability')->values()->all(),
                'countries' => $provider->countries
                    ->map(fn ($coverage): array => [
                        'country_code' => $coverage->country_code,
                        'settlement_currency' => $coverage->settlement_currency,
                        'merchant_onboarding' => (bool) $coverage->merchant_onboarding,
                        'transaction_capable' => (bool) $coverage->transaction_capable,
                        'matches_store_country' => $coverage->country_code === $countryCode,
                    ])
                    ->values()
                    ->all(),
                'methods' => $provider->methods
                    ->where('is_active', true)
                    ->map(fn ($method): array => [
                        'code' => $method->method_code,
                        'name' => $method->method?->localizedName(),
                        'country_code' => $method->country_code,
                        'source_id' => $method->source_id,
                        'priority' => (int) $method->priority,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
