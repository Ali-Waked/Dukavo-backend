<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Payment;
use App\Models\PaymentTransaction;

/**
 * Append-only writer for the provider call ledger.
 *
 * Callers pass the payloads they sent and received; this service removes
 * credential-shaped keys recursively before persisting them. An existing
 * idempotency key returns the original row rather than rewriting history.
 */
class PaymentLedgerService
{
    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $responsePayload
     */
    public function record(
        Payment $payment,
        PaymentTransactionType $type,
        PaymentTransactionStatus $status,
        string $amount,
        string $idempotencyKey,
        array $requestPayload = [],
        array $responsePayload = [],
        ?string $providerTransactionId = null,
        ?string $providerStatus = null,
        ?string $failureReason = null,
    ): PaymentTransaction {
        $existing = PaymentTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof PaymentTransaction) {
            return $existing;
        }

        return PaymentTransaction::query()->create([
            'tenant_id' => $payment->tenant_id,
            'store_id' => $payment->store_id,
            'payment_id' => $payment->getKey(),
            'order_id' => $payment->order_id,
            'provider_id' => $payment->provider_id,
            'type' => $type,
            'status' => $status,
            'amount' => $amount,
            'currency' => $payment->currency,
            'provider_transaction_id' => $providerTransactionId,
            'provider_status' => $providerStatus,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => $this->redact($requestPayload),
            'response_payload' => $this->redact($responsePayload),
            'failure_reason' => $failureReason,
            'processed_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['authorization', 'secret', 'secret_key', 'token', 'access_token'], true)) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
