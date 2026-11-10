<?php

declare(strict_types=1);

namespace App\Services\Payments\Contracts;

use App\Models\StorePaymentAccount;
use App\Services\Payments\Data\PaymentChargeRequest;
use App\Services\Payments\Data\PaymentChargeResult;
use App\Services\Payments\Data\PaymentConnectionResult;
use App\Services\Payments\Data\PaymentRefundRequest;
use App\Services\Payments\Data\PaymentRefundResult;
use App\Services\Payments\Data\PaymentStatusResult;
use App\Services\Payments\Data\PaymentWebhookRequest;
use App\Services\Payments\Data\PaymentWebhookResult;

/**
 * The only surface the rest of the application knows about a provider.
 *
 * Adapters translate between Dukavo's provider-independent value objects and a
 * vendor's HTTP API. Nothing above this contract branches on a provider name.
 */
interface PaymentProviderInterface
{
    public function code(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function createCharge(PaymentChargeRequest $request, StorePaymentAccount $account): PaymentChargeResult;

    public function fetchCharge(string $providerReference, StorePaymentAccount $account): PaymentStatusResult;

    public function refund(PaymentRefundRequest $request, StorePaymentAccount $account): PaymentRefundResult;

    public function verifyWebhook(PaymentWebhookRequest $request, StorePaymentAccount $account): PaymentWebhookResult;

    public function testConnection(StorePaymentAccount $account): PaymentConnectionResult;
}
