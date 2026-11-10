<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\BusinessRuleViolation;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Models\StorePaymentAccount;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Payments\Data\PaymentRefundRequest;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refunds against a captured provider charge.
 *
 * A refund may never exceed what the original charge still has available, may
 * never touch an attempt that was not captured, and is rejected unless the
 * provider adapter actually implements refunds. Pending refunds count against
 * the balance so two concurrent clicks cannot over-refund the same charge.
 */
class PaymentRefundService
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentLedgerService $ledger,
        private readonly OrderService $orders,
    ) {}

    public function refund(
        Payment $payment,
        string $amount,
        ?string $reason = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): PaymentRefund {
        $idempotencyKey = trim((string) $idempotencyKey);

        if ($idempotencyKey !== '') {
            $existing = PaymentRefund::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof PaymentRefund) {
                if ((int) $existing->payment_id !== (int) $payment->getKey()) {
                    throw BusinessRuleViolation::because('That refund reference belongs to another payment.');
                }

                return $existing;
            }
        }

        $idempotencyKey = $idempotencyKey !== ''
            ? $idempotencyKey
            : 'refund_'.Str::lower(Str::random(28));

        $currency = strtoupper((string) $payment->currency);
        $normalised = Money::normalize($amount, $currency);

        if (! Money::isPositive($normalised, $currency)) {
            throw BusinessRuleViolation::because('A refund amount must be greater than zero.');
        }

        $provider = $payment->provider()->first();
        $account = $payment->account()->first();
        $refund = $this->reserve($payment, $provider, $account, $normalised, $currency, $reason, $actor, $idempotencyKey);

        $request = new PaymentRefundRequest(
            providerChargeReference: (string) $payment->provider_reference,
            clientReference: 'refund:'.$idempotencyKey,
            amount: $normalised,
            currency: $currency,
            reason: $reason,
        );

        try {
            $result = $this->providers->adapterFor($provider)->refund($request, $account);
        } catch (PaymentProviderException $exception) {
            $this->fail($refund, $payment, $exception->getMessage(), $request->amount, ['error' => $exception->getMessage()]);

            return $refund->refresh();
        } catch (\Throwable $exception) {
            report($exception);

            $message = 'The payment provider could not be reached. The refund was not sent.';
            $this->fail($refund, $payment, $message, $request->amount, ['error' => 'transport_failure']);

            return $refund->refresh();
        }

        $ledgerStatus = match ($result->status) {
            PaymentRefundStatus::Succeeded => PaymentTransactionStatus::Succeeded,
            PaymentRefundStatus::Failed => PaymentTransactionStatus::Failed,
            default => PaymentTransactionStatus::Pending,
        };

        $this->ledger->record(
            $payment,
            PaymentTransactionType::Refund,
            $ledgerStatus,
            $request->amount,
            'refund:'.$idempotencyKey,
            [
                'charge_id' => $payment->provider_reference,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'reason' => $reason,
                'client_reference' => $request->clientReference,
            ],
            $result->raw,
            $result->providerReference,
            $result->status->value,
            $result->failureReason,
        );

        $refund->forceFill([
            'status' => $result->status,
            'provider_refund_id' => $result->providerReference,
            'failure_reason' => $result->status === PaymentRefundStatus::Failed
                ? ($result->failureReason ?? 'The payment provider declined the refund.')
                : null,
            'processed_at' => $result->status === PaymentRefundStatus::Pending ? null : now(),
        ])->save();

        if ($result->status === PaymentRefundStatus::Succeeded) {
            $this->syncOrderRefundState($payment);
        }

        return $refund->refresh();
    }

    private function reserve(
        Payment $payment,
        ?PaymentProvider $provider,
        ?StorePaymentAccount $account,
        string $amount,
        string $currency,
        ?string $reason,
        ?User $actor,
        string $idempotencyKey,
    ): PaymentRefund {
        return DB::transaction(function () use ($payment, $provider, $account, $amount, $currency, $reason, $actor, $idempotencyKey): PaymentRefund {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $payment->isSucceeded()) {
                throw BusinessRuleViolation::because('Only a captured payment can be refunded.');
            }

            if ($payment->provider_reference === null || trim((string) $payment->provider_reference) === '') {
                throw BusinessRuleViolation::because('This payment has no provider charge reference to refund.');
            }

            if (! $provider instanceof PaymentProvider) {
                throw BusinessRuleViolation::because('The payment provider is no longer available.');
            }

            if (! $provider->supports('refunds')) {
                throw BusinessRuleViolation::because($provider->name.' does not support refunds.');
            }

            if (! $account instanceof StorePaymentAccount || ! $account->isActive()) {
                throw BusinessRuleViolation::because('The payment connection is not active, so a refund cannot be sent.');
            }

            $reserved = '0';

            $refunds = PaymentRefund::query()
                ->where('payment_id', $payment->getKey())
                ->whereIn('status', [PaymentRefundStatus::Pending->value, PaymentRefundStatus::Succeeded->value])
                ->get(['amount', 'currency']);

            foreach ($refunds as $refund) {
                $reserved = Money::add($reserved, $refund->amount, $currency);
            }

            $refundable = Money::subtract($payment->amount, $reserved, $currency);

            if (Money::compare($amount, $refundable, $currency) > 0) {
                throw BusinessRuleViolation::because(
                    'The refund exceeds the remaining refundable amount of '.$refundable.' '.$currency.'.',
                    ['payment_id' => $payment->getKey(), 'refundable' => $refundable],
                    'refund_exceeds_balance',
                );
            }

            return PaymentRefund::query()->create([
                'tenant_id' => $payment->tenant_id,
                'store_id' => $payment->store_id,
                'payment_id' => $payment->getKey(),
                'order_id' => $payment->order_id,
                'provider_id' => $provider->getKey(),
                'store_payment_account_id' => $account->getKey(),
                'requested_by' => $actor?->getKey(),
                'status' => PaymentRefundStatus::Pending,
                'amount' => $amount,
                'currency' => $currency,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Apply an asynchronous provider refund update, normally delivered by a
     * signed webhook. The same balance rule decides whether the order itself is
     * now fully refunded.
     */
    public function applyProviderStatus(
        PaymentRefund $refund,
        PaymentRefundStatus $status,
        ?string $providerReference = null,
        ?string $failureReason = null,
    ): PaymentRefund {
        $refund->forceFill([
            'status' => $status,
            'provider_refund_id' => $providerReference ?? $refund->provider_refund_id,
            'failure_reason' => $status === PaymentRefundStatus::Failed ? $failureReason : null,
            'processed_at' => $status === PaymentRefundStatus::Pending ? null : now(),
        ])->save();

        if ($status === PaymentRefundStatus::Succeeded) {
            $payment = $refund->payment()->first();

            if ($payment instanceof Payment) {
                $this->syncOrderRefundState($payment);
            }
        }

        return $refund->refresh();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fail(PaymentRefund $refund, Payment $payment, string $message, string $amount, array $response): void
    {
        $this->ledger->record(
            $payment,
            PaymentTransactionType::Refund,
            PaymentTransactionStatus::Failed,
            $amount,
            'refund:'.$refund->idempotency_key,
            ['charge_id' => $payment->provider_reference, 'amount' => $amount, 'currency' => $payment->currency],
            $response,
            null,
            'failed',
            $message,
        );

        $refund->forceFill([
            'status' => PaymentRefundStatus::Failed,
            'failure_reason' => $message,
            'processed_at' => now(),
        ])->save();
    }

    private function syncOrderRefundState(Payment $payment): void
    {
        $refunded = '0';

        $refunds = PaymentRefund::query()
            ->where('payment_id', $payment->getKey())
            ->where('status', PaymentRefundStatus::Succeeded->value)
            ->get(['amount']);

        foreach ($refunds as $refund) {
            $refunded = Money::add($refunded, $refund->amount, (string) $payment->currency);
        }

        if (Money::compare($refunded, $payment->amount, (string) $payment->currency) < 0) {
            return;
        }

        $order = $payment->order()->first();

        if ($order !== null) {
            $this->orders->markRefunded($order);
        }
    }
}
