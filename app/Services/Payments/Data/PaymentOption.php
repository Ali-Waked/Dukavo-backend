<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

use App\Models\PaymentMethodDefinition;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderMethod;
use App\Models\StorePaymentAccount;

/**
 * A resolved, sellable online method for one store.
 *
 * This object is server-only: it contains the provider source id and the
 * connection model. API resources expose the safe projection, never this object
 * directly.
 */
final readonly class PaymentOption
{
    public function __construct(
        public PaymentMethodDefinition $method,
        public PaymentProvider $provider,
        public PaymentProviderMethod $providerMethod,
        public StorePaymentAccount $account,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(?string $locale = null): array
    {
        return [
            'code' => $this->method->code,
            'label' => $this->method->localizedName($locale),
            'description' => $this->method->localizedDescription($locale),
            'kind' => $this->method->kind,
            'provider' => $this->provider->code,
            'provider_name' => $this->provider->name,
            'environment' => $this->account->environment,
        ];
    }
}
