<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentProvider;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\Providers\TapPaymentProvider;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves a provider adapter from the database catalogue.
 *
 * A provider row without an adapter, or with integration disabled, cannot be
 * resolved. This is what stops a newly catalogued provider from being offered
 * before the code that talks to it exists.
 */
class PaymentProviderManager
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, class-string<PaymentProviderInterface>>
     */
    public function adapters(): array
    {
        return [
            'tap' => TapPaymentProvider::class,
        ];
    }

    public function adapterFor(PaymentProvider $provider, bool $requireEnabled = true): PaymentProviderInterface
    {
        $class = $this->adapters()[$provider->code] ?? null;

        if ($class === null) {
            throw new RuntimeException("No payment adapter is registered for [{$provider->code}].");
        }

        if ($requireEnabled && ! $provider->integration_enabled) {
            throw new RuntimeException("Payment provider [{$provider->code}] is not enabled for integration.");
        }

        $adapter = $this->container->make($class);

        if (! $adapter instanceof PaymentProviderInterface) {
            throw new RuntimeException("The adapter for [{$provider->code}] does not implement the contract.");
        }

        return $adapter;
    }

    public function find(string $code): ?PaymentProvider
    {
        return PaymentProvider::query()->where('code', $code)->first();
    }

    public function codeFor(PaymentProvider $provider): PaymentProviderInterface
    {
        return $this->adapterFor($provider);
    }
}
