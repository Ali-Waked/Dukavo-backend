<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Payments\StorePaymentProviderRequest;
use App\Http\Requests\Platform\Payments\UpdatePaymentProviderRequest;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCapability;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Platform provider catalogue.
 *
 * A provider row carries no credential and this controller has no merchant
 * detail. `integration_enabled` is the platform's statement that an adapter
 * exists; `status` is the commercial decision to offer it.
 */
class PaymentProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = PaymentProvider::query()
            ->with(['countries.country', 'capabilities', 'methods.method'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success('', [
            'providers' => $providers->map(fn (PaymentProvider $provider): array => $this->payload($provider))->values()->all(),
        ]);
    }

    public function store(StorePaymentProviderRequest $request): JsonResponse
    {
        $provider = PaymentProvider::query()->create($request->safe()->except('capabilities'));

        $this->syncCapabilities($provider, (array) $request->validated('capabilities', []));
        $provider->load(['countries.country', 'capabilities', 'methods.method']);

        return ApiResponse::created('Provider created.', $this->payload($provider));
    }

    public function update(UpdatePaymentProviderRequest $request, PaymentProvider $provider): JsonResponse
    {
        $provider->fill($request->safe()->except('capabilities'))->save();

        if ($request->has('capabilities')) {
            $this->syncCapabilities($provider, (array) $request->validated('capabilities', []));
        }

        $provider->load(['countries.country', 'capabilities', 'methods.method']);

        return ApiResponse::success('Provider updated.', $this->payload($provider));
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function syncCapabilities(PaymentProvider $provider, array $capabilities): void
    {
        $capabilities = array_values(array_unique(array_filter(array_map(
            static fn (mixed $capability): string => strtolower(trim((string) $capability)),
            $capabilities,
        ))));

        PaymentProviderCapability::query()
            ->where('provider_id', $provider->getKey())
            ->whereNotIn('capability', $capabilities)
            ->delete();

        foreach ($capabilities as $capability) {
            PaymentProviderCapability::query()->firstOrCreate([
                'provider_id' => $provider->getKey(),
                'capability' => $capability,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'status' => $provider->status->value,
            'status_label' => $provider->status->label(),
            'integration_enabled' => (bool) $provider->integration_enabled,
            'adapter' => $provider->adapter,
            'docs_url' => $provider->docs_url,
            'webhook_docs_url' => $provider->webhook_docs_url,
            'notes' => $provider->notes,
            'last_verified_at' => $provider->last_verified_at?->toIso8601String(),
            'capabilities' => $provider->capabilities->pluck('capability')->values()->all(),
            'countries' => $provider->countries->map(fn ($coverage): array => [
                'id' => $coverage->id,
                'country_code' => $coverage->country_code,
                'country_name' => $coverage->country?->name,
                'settlement_currency' => $coverage->settlement_currency,
                'merchant_onboarding' => (bool) $coverage->merchant_onboarding,
                'transaction_capable' => (bool) $coverage->transaction_capable,
                'source_url' => $coverage->source_url,
                'notes' => $coverage->notes,
                'verified_at' => $coverage->verified_at?->toIso8601String(),
            ])->values()->all(),
            'methods' => $provider->methods->map(fn ($method): array => [
                'id' => $method->id,
                'method_code' => $method->method_code,
                'method_name' => $method->method?->name,
                'country_code' => $method->country_code,
                'source_id' => $method->source_id,
                'priority' => (int) $method->priority,
                'is_active' => (bool) $method->is_active,
                'config' => $method->config,
            ])->values()->all(),
        ];
    }
}
