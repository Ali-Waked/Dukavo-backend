<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus as OrderPaymentStatus;
use App\Models\Payment;
use App\Models\StorePaymentAccount;
use App\Services\Orders\OrderService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Applies an authoritative provider status to a payment attempt.
 *
 * A captured payment is never downgraded by a late or out-of-order callback,
 * and a success is only accepted when the amount and currency still match the
 * server-priced attempt. Marking the order paid happens here so every path
 * (webhook, status sync) uses the same rule.
 */
class PaymentStatusService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentProviderManager $providers,
    ) {}

    public function apply(
        Payment $payment,
        PaymentAttemptStatus $status,
        ?string $providerReference = null,
        ?string $failureReason = null,
    ): Payment {
        return DB::transaction(function () use ($payment, $status, $providerReference, $failureReason): Payment {
            Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $payment->refresh();

            if ($payment->status === PaymentAttemptStatus::Succeeded && $status !== PaymentAttemptStatus::Succeeded) {
                return $payment;
            }

            $attributes = [
                'status' => $status,
                'failure_reason' => $status === PaymentAttemptStatus::Succeeded ? null : $failureReason,
            ];

            if ($providerReference !== null && trim($providerReference) !== '') {
                $attributes['provider_reference'] = $providerReference;
            }

            if ($status === PaymentAttemptStatus::Succeeded) {
                $attributes['paid_at'] = $payment->paid_at ?? now();
                $attributes['checkout_url'] = null;
            }

            $payment->forceFill($attributes)->save();

            if ($status === PaymentAttemptStatus::Succeeded) {
                $order = $payment->order()->first();

                if ($order !== null && $order->payment_status !== OrderPaymentStatus::Paid) {
                    $this->orders->markPaid($order);
                }
            }

            return $payment->refresh();
        });
    }

    /**
     * Ask the provider for its current answer. This is used by an explicit
     * merchant/staff action, never by a public page load, so a polling browser
     * cannot multiply provider traffic.
     */
    public function sync(Payment $payment): Payment
    {
        $account = $payment->account()->first();

        if (! $account instanceof StorePaymentAccount || ! $account->isActive()) {
            return $payment;
        }

        $provider = $payment->provider()->first();

        if ($provider === null || $payment->provider_reference === null) {
            return $payment;
        }

        try {
            $result = $this->providers->adapterFor($provider)->fetchCharge(
                (string) $payment->provider_reference,
                $account,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $payment;
        }

        if ($result->amount !== null && ! Money::equals($result->amount, $payment->amount, $payment->currency)) {
            $payment->forceFill([
                'failure_reason' => 'The provider reported an amount that does not match this payment.',
            ])->save();

            return $payment->refresh();
        }

        if ($result->currency !== null && strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
            $payment->forceFill([
                'failure_reason' => 'The provider reported a currency that does not match this payment.',
            ])->save();

            return $payment->refresh();
        }

        return $this->apply(
            $payment,
            $result->status,
            $result->providerReference,
            $result->failureReason,
        );
    }
}
