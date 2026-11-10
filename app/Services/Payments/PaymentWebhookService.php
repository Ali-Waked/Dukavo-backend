<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentWebhookStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Models\PaymentWebhookEvent;
use App\Models\StorePaymentAccount;
use App\Models\Tenant;
use App\Services\Payments\Data\PaymentWebhookRequest;
use App\Services\Payments\Data\PaymentWebhookResult;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Processes provider callbacks.
 *
 * The raw callback is stored before any financial decision. A callback only
 * changes state when the adapter verifies its signature using the merchant
 * connection that owns the referenced attempt, and a success additionally has
 * to match the amount, currency and provider reference recorded at checkout.
 * Duplicate provider event ids are acknowledged without repeating the effect.
 */
class PaymentWebhookService
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentStatusService $statuses,
        private readonly PaymentRefundService $refunds,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(string $providerCode, PaymentWebhookRequest $request): PaymentWebhookEvent
    {
        $provider = PaymentProvider::query()->where('code', strtolower(trim($providerCode)))->first();

        if (! $provider instanceof PaymentProvider) {
            throw BusinessRuleViolation::because('Unknown payment provider.');
        }

        [$payment, $order] = $this->locatePayment($request);

        if (! $payment instanceof Payment) {
            return $this->recordUnmatched($provider, $request);
        }

        $tenant = Tenant::query()->find($payment->tenant_id);

        if (! $tenant instanceof Tenant) {
            throw BusinessRuleViolation::because('The payment tenant no longer exists.');
        }

        return $this->tenants->run(
            $tenant,
            fn (): PaymentWebhookEvent => $this->process($provider, $request, $payment, $order),
        );
    }

    private function process(
        PaymentProvider $provider,
        PaymentWebhookRequest $request,
        Payment $payment,
        ?Order $order,
    ): PaymentWebhookEvent {
        $account = $payment->account()->first();

        if (! $account instanceof StorePaymentAccount) {
            return $this->recordFailure(
                $provider,
                $request,
                $payment,
                'The payment connection for this attempt no longer exists.',
            );
        }

        if ((int) $account->provider_id !== (int) $provider->getKey()) {
            return $this->recordFailure(
                $provider,
                $request,
                $payment,
                'The callback provider does not own this payment attempt.',
            );
        }

        try {
            $result = $this->providers
                ->adapterFor($provider, requireEnabled: false)
                ->verifyWebhook($request, $account);
        } catch (PaymentProviderException $exception) {
            $this->recordFailure($provider, $request, $payment, $exception->getMessage());

            throw $exception;
        }

        $event = $this->openEvent($provider, $request, $payment, $result);

        if ($event->status !== PaymentWebhookStatus::Received) {
            return $event;
        }

        try {
            $failure = $result->status === null
                ? $this->applyRefundEvent($payment, $result)
                : $this->applyChargeEvent($payment, $result);

            return $this->closeEvent($event, $failure);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->closeEvent($event, 'The callback could not be applied: '.$exception->getMessage());
        }
    }

    private function applyChargeEvent(Payment $payment, PaymentWebhookResult $result): ?string
    {
        if ($result->providerReference !== null
            && $payment->provider_reference !== null
            && trim((string) $payment->provider_reference) !== ''
            && $result->providerReference !== (string) $payment->provider_reference) {
            $this->statuses->apply(
                $payment,
                PaymentAttemptStatus::Failed,
                null,
                'The provider callback referenced a different charge than this attempt.',
            );

            return 'Provider reference mismatch.';
        }

        $isSuccess = $result->status === PaymentAttemptStatus::Succeeded;

        if ($isSuccess && $result->amount !== null && ! Money::equals($result->amount, $payment->amount, (string) $payment->currency)) {
            $this->statuses->apply(
                $payment,
                PaymentAttemptStatus::Failed,
                $result->providerReference,
                'The provider reported an amount that does not match this payment.',
            );

            return 'Amount mismatch.';
        }

        if ($isSuccess && $result->currency !== null && strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
            $this->statuses->apply(
                $payment,
                PaymentAttemptStatus::Failed,
                $result->providerReference,
                'The provider reported a currency that does not match this payment.',
            );

            return 'Currency mismatch.';
        }

        if ($result->status !== null) {
            $this->statuses->apply(
                $payment,
                $result->status,
                $result->providerReference,
                $result->failureReason,
            );
        }

        return $result->failureReason;
    }

    private function applyRefundEvent(Payment $payment, PaymentWebhookResult $result): ?string
    {
        $refund = PaymentRefund::query()
            ->where('payment_id', $payment->getKey())
            ->when(
                $result->providerReference !== null,
                fn ($query) => $query->where('provider_refund_id', $result->providerReference),
            )
            ->latest()
            ->first();

        if (! $refund instanceof PaymentRefund) {
            $refund = PaymentRefund::query()
                ->where('payment_id', $payment->getKey())
                ->where('status', PaymentRefundStatus::Pending->value)
                ->latest()
                ->first();
        }

        if (! $refund instanceof PaymentRefund) {
            return 'No refund matched this callback.';
        }

        $status = $this->refundStatus((string) ($result->raw['status'] ?? ''));

        $this->refunds->applyProviderStatus(
            $refund,
            $status,
            $result->providerReference,
            $status === PaymentRefundStatus::Failed ? 'The payment provider declined the refund.' : null,
        );

        return $status === PaymentRefundStatus::Failed ? 'Refund failed at the provider.' : null;
    }

    private function refundStatus(string $status): PaymentRefundStatus
    {
        return match (strtoupper($status)) {
            'REFUNDED', 'CAPTURED', 'SUCCEEDED' => PaymentRefundStatus::Succeeded,
            'FAILED', 'DECLINED', 'CANCELLED', 'CANCELED' => PaymentRefundStatus::Failed,
            default => PaymentRefundStatus::Pending,
        };
    }

    private function openEvent(
        PaymentProvider $provider,
        PaymentWebhookRequest $request,
        Payment $payment,
        PaymentWebhookResult $result,
    ): PaymentWebhookEvent {
        $event = $this->findEvent($provider, $result->eventId);

        if ($event instanceof PaymentWebhookEvent) {
            return $event;
        }

        try {
            return PaymentWebhookEvent::query()->create([
                'provider_id' => $provider->getKey(),
                'store_payment_account_id' => $payment->store_payment_account_id,
                'payment_id' => $payment->getKey(),
                'tenant_id' => $payment->tenant_id,
                'store_id' => $payment->store_id,
                'event_id' => $result->eventId,
                'event_type' => $result->eventType,
                'status' => PaymentWebhookStatus::Received,
                'signature_valid' => true,
                'payload' => $this->safePayload($result->raw ?: $request->payload),
                'headers' => $this->safeHeaders($request->headers),
                'processed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->findEvent($provider, $result->eventId) ?? PaymentWebhookEvent::query()->create([
                'provider_id' => $provider->getKey(),
                'store_payment_account_id' => $payment->store_payment_account_id,
                'payment_id' => $payment->getKey(),
                'tenant_id' => $payment->tenant_id,
                'store_id' => $payment->store_id,
                'event_id' => $result->eventId,
                'event_type' => $result->eventType,
                'status' => PaymentWebhookStatus::Received,
                'signature_valid' => true,
                'payload' => $this->safePayload($result->raw ?: $request->payload),
                'headers' => $this->safeHeaders($request->headers),
            ]);
        }
    }

    private function closeEvent(PaymentWebhookEvent $event, ?string $failure): PaymentWebhookEvent
    {
        if ($event->status === PaymentWebhookStatus::Processed) {
            return $event;
        }

        $event->forceFill([
            'status' => $failure === null ? PaymentWebhookStatus::Processed : PaymentWebhookStatus::Failed,
            'failure_reason' => $failure,
            'processed_at' => now(),
        ])->save();

        return $event->refresh();
    }

    private function recordFailure(
        PaymentProvider $provider,
        PaymentWebhookRequest $request,
        Payment $payment,
        string $message,
    ): PaymentWebhookEvent {
        $eventId = $this->eventId($request);
        $event = $this->findEvent($provider, $eventId);

        if ($event instanceof PaymentWebhookEvent && $event->status === PaymentWebhookStatus::Processed) {
            return $event;
        }

        $attributes = [
            'provider_id' => $provider->getKey(),
            'store_payment_account_id' => $payment->store_payment_account_id,
            'payment_id' => $payment->getKey(),
            'tenant_id' => $payment->tenant_id,
            'store_id' => $payment->store_id,
            'event_id' => $eventId,
            'event_type' => (string) (($request->payload['object'] ?? 'event').'.'.($request->payload['status'] ?? 'unknown')),
            'status' => PaymentWebhookStatus::Failed,
            'signature_valid' => false,
            'payload' => $this->safePayload($request->payload),
            'headers' => $this->safeHeaders($request->headers),
            'failure_reason' => $message,
            'processed_at' => now(),
        ];

        if ($event instanceof PaymentWebhookEvent) {
            $event->forceFill($attributes)->save();

            return $event->refresh();
        }

        try {
            return PaymentWebhookEvent::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            $event = $this->findEvent($provider, $eventId);

            if ($event instanceof PaymentWebhookEvent) {
                return $event;
            }

            throw BusinessRuleViolation::because('The payment callback could not be recorded.');
        }
    }

    private function recordUnmatched(PaymentProvider $provider, PaymentWebhookRequest $request): PaymentWebhookEvent
    {
        $eventId = $this->eventId($request);
        $event = $this->findEvent($provider, $eventId);

        if ($event instanceof PaymentWebhookEvent) {
            return $event;
        }

        try {
            return PaymentWebhookEvent::query()->create([
                'provider_id' => $provider->getKey(),
                'event_id' => $eventId,
                'event_type' => (string) (($request->payload['object'] ?? 'event').'.'.($request->payload['status'] ?? 'unknown')),
                'status' => PaymentWebhookStatus::Ignored,
                'signature_valid' => false,
                'payload' => $this->safePayload($request->payload),
                'headers' => $this->safeHeaders($request->headers),
                'failure_reason' => 'No payment attempt matched this callback reference.',
                'processed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->findEvent($provider, $eventId) ?? PaymentWebhookEvent::query()->create([
                'provider_id' => $provider->getKey(),
                'event_id' => $eventId,
                'event_type' => 'event.unknown',
                'status' => PaymentWebhookStatus::Ignored,
                'signature_valid' => false,
                'payload' => $this->safePayload($request->payload),
            ]);
        }
    }

    private function findEvent(PaymentProvider $provider, string $eventId): ?PaymentWebhookEvent
    {
        return PaymentWebhookEvent::query()
            ->where('provider_id', $provider->getKey())
            ->where('event_id', $eventId)
            ->first();
    }

    /**
     * @return array{0: Payment|null, 1: Order|null}
     */
    private function locatePayment(PaymentWebhookRequest $request): array
    {
        $clientReference = trim((string) data_get($request->payload, 'reference.transaction'));

        if ($clientReference !== '') {
            $payment = Payment::query()
                ->acrossTenants()
                ->where('client_reference', $clientReference)
                ->first();

            if ($payment instanceof Payment) {
                return [$payment, null];
            }
        }

        $orderNumber = trim((string) data_get($request->payload, 'reference.order'));

        if ($orderNumber === '') {
            return [null, null];
        }

        $order = Order::query()->acrossTenants()->where('order_number', $orderNumber)->first();

        if (! $order instanceof Order) {
            return [null, null];
        }

        $payment = Payment::query()
            ->acrossTenants()
            ->where('order_id', $order->getKey())
            ->latest('attempt_number')
            ->first();

        return [$payment, $order];
    }

    private function eventId(PaymentWebhookRequest $request): string
    {
        $id = trim((string) ($request->payload['id'] ?? ''));
        $digest = hash('sha256', $request->rawBody);

        return $id !== '' ? $id.'_'.substr($digest, 0, 32) : $digest;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        foreach (['authorization', 'token', 'secret', 'secret_key', 'card', 'customer'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function safeHeaders(array $headers): array
    {
        $safe = [];

        foreach ($headers as $key => $value) {
            $key = strtolower((string) $key);

            if (in_array($key, ['authorization', 'cookie', 'set-cookie', 'x-api-key'], true)) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }
}
