<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentConnectionStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\PaymentCountry;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderMethod;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Services\Payments\Data\PaymentOption;
use Illuminate\Support\Collection;

/**
 * Decides which online methods a store may actually offer.
 *
 * The rules are cumulative and fail closed:
 *  1. the store has an active country;
 *  2. a provider allows new merchant onboarding there, with a transaction
 *     capability and a documented settlement currency;
 *  3. the provider is commercially active and its adapter is enabled;
 *  4. the store has an `active` connection whose provider-side test passed;
 *  5. the connection environment matches the deployment's checkout environment;
 *  6. the provider has an active source id for the method in that country.
 *
 * No rule can be satisfied by a browser payload. A merchant cannot choose a
 * provider directly at checkout either: the server resolves the connection.
 */
class PaymentEligibilityService
{
    /**
     * @return list<PaymentOption>
     */
    public function options(Store $store, ?string $locale = null): array
    {
        [$options] = $this->evaluate($store);

        return $options;
    }

    /**
     * @return array{0: list<PaymentOption>, 1: list<string>}
     */
    public function evaluate(Store $store): array
    {
        $countryCode = strtoupper(trim((string) $store->country));

        if ($countryCode === '') {
            return [[], ['Set the store country before offering online payment.']];
        }

        $country = PaymentCountry::query()->where('code', $countryCode)->first();

        if (! $country instanceof PaymentCountry || ! $country->is_active) {
            return [[], ['Online payment is not available in this country yet.']];
        }

        $environment = (string) config('payments.checkout_environment', 'sandbox');
        $currency = strtoupper(trim((string) $store->currency));

        $accounts = StorePaymentAccount::query()
            ->where('store_id', $store->getKey())
            ->where('status', PaymentConnectionStatus::Active)
            ->where('environment', $environment)
            ->whereNotNull('last_verified_at')
            ->with(['provider.capabilities', 'provider'])
            ->get();

        if ($accounts->isEmpty()) {
            return [[], ['No verified '.$environment.' payment provider connection is configured for this store.']];
        }

        $options = [];
        $reasons = [];

        foreach ($accounts as $account) {
            $provider = $account->provider;

            if (! $provider instanceof PaymentProvider || ! $provider->isOffered() || ! $account->isActive()) {
                continue;
            }

            if (! $provider->supports('charges')) {
                $reasons[] = $provider->name.' does not support charges.';

                continue;
            }

            $coverage = $provider->countries()
                ->where('country_code', $countryCode)
                ->first();

            if ($coverage === null || ! $coverage->merchant_onboarding || ! $coverage->transaction_capable) {
                $reasons[] = $provider->name.' does not accept new merchants in '.$country->name.'.';

                continue;
            }

            if (strtoupper((string) $coverage->settlement_currency) !== $currency) {
                $reasons[] = $provider->name.' settles '.$coverage->settlement_currency
                    .' in '.$country->name.', but this store is priced in '.$currency.'.';

                continue;
            }

            $methods = $this->methodsFor($provider, $countryCode);

            if ($methods->isEmpty()) {
                $reasons[] = $provider->name.' has no active payment method for '.$country->name.'.';

                continue;
            }

            foreach ($methods as $method) {
                if ($method->method === null) {
                    continue;
                }

                $options[] = new PaymentOption(
                    method: $method->method,
                    provider: $provider,
                    providerMethod: $method,
                    account: $account,
                );
            }
        }

        if ($options === []) {
            return [[], array_values(array_unique($reasons)) ?: ['Online payment is not available for this store yet.']];
        }

        return [$options, []];
    }

    /**
     * Resolve one method, or the provider's first offered method when the
     * checkout did not name one.
     */
    public function resolve(Store $store, ?string $methodCode = null): ?PaymentOption
    {
        $options = $this->options($store);
        $methodCode = strtolower(trim((string) $methodCode));

        foreach ($options as $option) {
            if ($methodCode === '' || $option->method->code === $methodCode) {
                return $option;
            }
        }

        return null;
    }

    public function assertAvailable(Store $store, ?string $methodCode = null): PaymentOption
    {
        [$options, $reasons] = $this->evaluate($store);
        $methodCode = strtolower(trim((string) $methodCode));

        foreach ($options as $option) {
            if ($methodCode === '' || $option->method->code === $methodCode) {
                return $option;
            }
        }

        $message = $methodCode === ''
            ? 'Online payment is not available for this store yet.'
            : 'The selected payment method is not available for this store.';

        throw BusinessRuleViolation::because(
            $message.($reasons !== [] ? ' '.$reasons[0] : ''),
            ['store_id' => $store->getKey(), 'method_code' => $methodCode, 'reasons' => $reasons],
            'payment_method_unavailable',
        );
    }

    /**
     * Country-specific rows win over global rows for the same method code.
     *
     * @return Collection<int, PaymentProviderMethod>
     */
    private function methodsFor(PaymentProvider $provider, string $countryCode): Collection
    {
        return PaymentProviderMethod::query()
            ->where('provider_id', $provider->getKey())
            ->where('is_active', true)
            ->where(function ($query) use ($countryCode): void {
                $query->whereNull('country_code')->orWhere('country_code', $countryCode);
            })
            ->whereHas('method', fn ($query) => $query->where('is_active', true))
            ->with('method')
            ->orderByRaw('CASE WHEN country_code IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority')
            ->get()
            ->unique(fn (PaymentProviderMethod $method): string => $method->method_code)
            ->values();
    }
}
