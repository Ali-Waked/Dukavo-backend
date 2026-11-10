<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus as OrderPaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\BusinessRuleViolation;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\StorePaymentAccount;
use App\Services\Payments\Data\PaymentChargeRequest;
use App\Services\Payments\Data\PaymentChargeResult;
use App\Services\Payments\Data\PaymentOption;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts and retries hosted online checkout attempts.
 *
 * The order is fully committed before a charge is sent to the provider. A
 * provider refusal therefore leaves a real order with a failed attempt that the
 * customer can retry; it never rolls the order back and it never reports a
 * browser redirect as payment. Amount and currency come from the server-priced
 * order and are normalised to the currency's own minor unit before they are
 * sent.
 */
class PaymentSessionService
{
    public function __construct(
        private readonly PaymentEligibilityService $eligibility,
        private readonly PaymentProviderManager $providers,
        private readonly PaymentLedgerService $ledger,
        private readonly PaymentStatusService $statuses,
    ) {}

    public function start(Order $order, ?string $methodCode = null): Payment
    {
        if ($order->payment_method !== PaymentMethod::Online) {
            throw BusinessRuleViolation::because('This order was not placed with online payment.');
        }

        if ($order->payment_status === OrderPaymentStatus::Paid) {
            throw BusinessRuleViolation::because('This order has already been paid.');
        }

        $store = $order->store()->first();

        if ($store === null) {
            throw BusinessRuleViolation::because('This order has no store.');
        }

        $option = $this->eligibility->assertAvailable($store, $methodCode);

        if ($option->account->store_id !== $store->getKey()) {
            throw BusinessRuleViolation::because('The selected payment connection is not available for this store.');
        }

        $amount = Money::normalize($order->grand_total, (string) $order->currency);

        if (! Money::isPositive($amount, (string) $order->currency)) {
            throw BusinessRuleViolation::because('An online payment needs an amount greater than zero.');
        }

        $payment = $this->createAttempt($order, $option, $amount);

        return $this->dispatch($payment, $option, $order);
    }

    /**
     * Create a new attempt only when the previous one can no longer be paid.
     * A still-open attempt is returned unchanged so a double click cannot result
     * in two charges for the same order.
     */
    public function retry(Order $order, ?string $methodCode = null): Payment
    {
        if ($order->payment_status === OrderPaymentStatus::Paid) {
            throw BusinessRuleViolation::because('This order has already been paid.');
        }

        $latest = Payment::query()
            ->where('order_id', $order->getKey())
            ->latest('attempt_number')
            ->first();

        if ($latest instanceof Payment) {
            $expired = $latest->expires_at !== null && $latest->expires_at->isPast();

            if (! $expired && $latest->checkout_url !== null && in_array($latest->status, [
                PaymentAttemptStatus::Pending,
                PaymentAttemptStatus::RequiresAction,
                PaymentAttemptStatus::Processing,
            ], true)) {
                return $latest;
            }

            if ($expired && ! $latest->status->isFinal()) {
                $this->statuses->apply(
                    $latest,
                    PaymentAttemptStatus::Expired,
                    null,
                    'The payment session expired before it was completed.',
                );
            }

            if ($latest->status === PaymentAttemptStatus::Succeeded) {
                throw BusinessRuleViolation::because('This order has already been paid.');
            }

            $methodCode ??= $latest->payment_method_code;
        }

        return $this->start($order, $methodCode);
    }

    private function createAttempt(Order $order, PaymentOption $option, string $amount): Payment
    {
        return DB::transaction(function () use ($order, $option, $amount): Payment {
            Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $attemptNumber = (int) Payment::query()
                ->where('order_id', $order->getKey())
                ->max('attempt_number') + 1;

            $ttl = (int) config('payments.session_ttl_minutes', 60);

            return Payment::query()->create([
                'tenant_id' => $order->tenant_id,
                'store_id' => $order->store_id,
                'order_id' => $order->getKey(),
                'provider_id' => $option->provider->getKey(),
                'store_payment_account_id' => $option->account->getKey(),
                'attempt_number' => $attemptNumber,
                'status' => PaymentAttemptStatus::Pending,
                'payment_method_code' => $option->method->code,
                'amount' => $amount,
                'currency' => strtoupper((string) $order->currency),
                'client_reference' => $this->clientReference(),
                'metadata' => [
                    'method_code' => $option->method->code,
                    'provider_code' => $option->provider->code,
                    'environment' => $option->account->environment,
                ],
                'expires_at' => $ttl > 0 ? now()->addMinutes($ttl) : null,
            ]);
        });
    }

    private function dispatch(Payment $payment, PaymentOption $option, Order $order): Payment
    {
        $provider = $payment->provider()->first();
        $account = $payment->account()->first();

        if (! $provider instanceof PaymentProvider || ! $account instanceof StorePaymentAccount) {
            $this->statuses->apply($payment, PaymentAttemptStatus::Failed, null, 'The payment connection is no longer available.');

            return $payment->refresh();
        }

        $request = new PaymentChargeRequest(
            orderReference: (string) $order->order_number,
            clientReference: (string) $payment->client_reference,
            amount: Money::normalize($payment->amount, (string) $payment->currency),
            currency: strtoupper((string) $payment->currency),
            methodCode: (string) $option->method->code,
            sourceId: (string) $option->providerMethod->source_id,
            description: 'Order '.$order->order_number,
            customerName: (string) $order->customer_name,
            customerEmail: $order->customer_email !== null ? (string) $order->customer_email : null,
            customerPhone: $order->customer_phone !== null ? (string) $order->customer_phone : null,
            redirectUrl: $this->redirectUrl($order),
            webhookUrl: $this->webhookUrl($provider),
            metadata: [
                'order_number' => $order->order_number,
                'attempt' => $payment->attempt_number,
            ],
        );

        $ledgerRequest = [
            'amount' => $request->amount,
            'currency' => $request->currency,
            'source_id' => $request->sourceId,
            'method_code' => $request->methodCode,
            'order_reference' => $request->orderReference,
            'client_reference' => $request->clientReference,
        ];

        try {
            $result = $this->providers->adapterFor($provider)->createCharge($request, $account);
        } catch (PaymentProviderException $exception) {
            $this->ledger->record(
                $payment,
                PaymentTransactionType::Charge,
                PaymentTransactionStatus::Failed,
                (string) $payment->amount,
                'charge:'.$payment->client_reference,
                $ledgerRequest,
                ['error' => $exception->getMessage()],
                null,
                'rejected',
                $exception->getMessage(),
            );

            $this->statuses->apply($payment, PaymentAttemptStatus::Failed, null, $exception->getMessage());

            return $payment->refresh();
        } catch (\Throwable $exception) {
            report($exception);

            $message = 'The payment provider could not be reached. Please try again.';

            $this->ledger->record(
                $payment,
                PaymentTransactionType::Charge,
                PaymentTransactionStatus::Failed,
                (string) $payment->amount,
                'charge:'.$payment->client_reference,
                $ledgerRequest,
                ['error' => 'transport_failure'],
                null,
                'error',
                $message,
            );

            $this->statuses->apply($payment, PaymentAttemptStatus::Failed, null, $message);

            return $payment->refresh();
        }

        $this->recordChargeResult($payment, $request, $result);

        return $payment->refresh();
    }

    private function recordChargeResult(Payment $payment, PaymentChargeRequest $request, PaymentChargeResult $result): void
    {
        $ledgerStatus = match ($result->status) {
            PaymentAttemptStatus::Succeeded => PaymentTransactionStatus::Succeeded,
            PaymentAttemptStatus::Failed, PaymentAttemptStatus::Cancelled, PaymentAttemptStatus::Expired => PaymentTransactionStatus::Failed,
            default => PaymentTransactionStatus::Pending,
        };

        $this->ledger->record(
            $payment,
            PaymentTransactionType::Charge,
            $ledgerStatus,
            (string) $payment->amount,
            'charge:'.$payment->client_reference,
            [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'source_id' => $request->sourceId,
                'method_code' => $request->methodCode,
                'order_reference' => $request->orderReference,
                'client_reference' => $request->clientReference,
            ],
            $result->raw,
            $result->providerReference,
            $result->status->value,
            $result->failureReason,
        );

        $attributes = [];

        if ($result->providerReference !== null && trim($result->providerReference) !== '') {
            $attributes['provider_reference'] = $result->providerReference;
        }

        if ($result->checkoutUrl !== null && trim($result->checkoutUrl) !== '') {
            $attributes['checkout_url'] = $result->checkoutUrl;
            $ttl = (int) config('payments.session_ttl_minutes', 60);

            if ($ttl > 0) {
                $attributes['expires_at'] = now()->addMinutes($ttl);
            }
        }

        if ($attributes !== []) {
            $payment->forceFill($attributes)->save();
            $payment->refresh();
        }

        if ($result->status === PaymentAttemptStatus::Succeeded) {
            $this->statuses->apply($payment, PaymentAttemptStatus::Succeeded, $result->providerReference);

            return;
        }

        if ($result->status === PaymentAttemptStatus::Failed) {
            $this->statuses->apply(
                $payment,
                PaymentAttemptStatus::Failed,
                $result->providerReference,
                $result->failureReason ?? 'The payment provider declined the charge.',
            );

            return;
        }

        if ($result->checkoutUrl === null && ! $result->status->isFinal()) {
            $this->statuses->apply(
                $payment,
                PaymentAttemptStatus::Failed,
                $result->providerReference,
                'The payment provider did not return a checkout page.',
            );

            return;
        }

        $this->statuses->apply($payment, $result->status, $result->providerReference, $result->failureReason);
    }

    private function redirectUrl(Order $order): string
    {
        $order->loadMissing(['store.tenant']);

        $tenantSlug = (string) ($order->store?->tenant?->slug ?? '');
        $storeSlug = (string) ($order->store?->slug ?? '');

        $path = str_replace(
            ['{tenant}', '{store}', '{token}'],
            [$tenantSlug, $storeSlug, (string) $order->tracking_token],
            (string) config('payments.return_path', '/shop/{tenant}/{store}/orders/{token}'),
        );

        return rtrim((string) config('dukavo.urls.frontend', config('app.url')), '/').'/'.ltrim($path, '/');
    }

    private function webhookUrl(PaymentProvider $provider): string
    {
        return url('/api/v1/webhooks/payments/'.rawurlencode((string) $provider->code));
    }

    private function clientReference(): string
    {
        do {
            $reference = 'dk_'.Str::lower(Str::random(28));
        } while (Payment::query()->withoutGlobalScopes()->where('client_reference', $reference)->exists());

        return $reference;
    }
}
