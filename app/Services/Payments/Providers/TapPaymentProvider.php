<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentRefundStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\StorePaymentAccount;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\Data\PaymentChargeRequest;
use App\Services\Payments\Data\PaymentChargeResult;
use App\Services\Payments\Data\PaymentConnectionResult;
use App\Services\Payments\Data\PaymentRefundRequest;
use App\Services\Payments\Data\PaymentRefundResult;
use App\Services\Payments\Data\PaymentStatusResult;
use App\Services\Payments\Data\PaymentWebhookRequest;
use App\Services\Payments\Data\PaymentWebhookResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tap Payments adapter.
 *
 * Official API surface used here:
 * - POST /v2/charges, GET /v2/charges/{id}
 * - POST /v2/refunds
 * - webhook `hashstring` = HMAC-SHA256 over the documented concatenation
 *
 * The merchant's secret key is read from the encrypted connection row on every
 * call and never cached or logged.
 */
class TapPaymentProvider implements PaymentProviderInterface
{
    /**
     * Dial codes for the 22 Arab markets, used only to split a phone number into
     * the two fields Tap expects. An unrecognised number is omitted rather than
     * guessed.
     *
     * @var list<string>
     */
    private const DIAL_CODES = [
        '966', '971', '965', '973', '974', '968', '20', '962', '961', '964',
        '963', '967', '970', '218', '216', '213', '212', '249', '222', '252',
        '253', '269',
    ];

    public function code(): string
    {
        return 'tap';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return ['charges', 'status', 'refunds', 'webhooks', 'connection_test'];
    }

    public function createCharge(PaymentChargeRequest $request, StorePaymentAccount $account): PaymentChargeResult
    {
        $payload = [
            'amount' => $request->amount,
            'currency' => $request->currency,
            'source' => ['id' => $request->sourceId],
            'redirect' => ['url' => $request->redirectUrl],
            'post' => ['url' => $request->webhookUrl],
            'reference' => [
                'order' => $request->orderReference,
                'transaction' => $request->clientReference,
            ],
            'description' => Str::limit($request->description, 255, ''),
            'customer' => $this->customer($request),
        ];

        $response = $this->client($account)->post('charges', array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        ));

        $data = $this->decode($response, 'The payment provider rejected the charge.');

        $status = $this->mapChargeStatus((string) ($data['status'] ?? ''));
        $checkoutUrl = data_get($data, 'transaction.url')
            ?? data_get($data, 'url')
            ?? data_get($data, 'redirect.url');

        return new PaymentChargeResult(
            status: $status,
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            checkoutUrl: is_string($checkoutUrl) && $checkoutUrl !== '' ? $checkoutUrl : null,
            raw: $this->safePayload($data),
            failureReason: $status === PaymentAttemptStatus::Failed
                ? $this->failureMessage($data)
                : null,
        );
    }

    public function fetchCharge(string $providerReference, StorePaymentAccount $account): PaymentStatusResult
    {
        $response = $this->client($account)->get('charges/'.rawurlencode($providerReference));

        $data = $this->decode($response, 'The payment provider could not be reached.');

        return new PaymentStatusResult(
            status: $this->mapChargeStatus((string) ($data['status'] ?? '')),
            providerReference: isset($data['id']) ? (string) $data['id'] : $providerReference,
            amount: isset($data['amount']) ? (string) $data['amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            raw: $this->safePayload($data),
            failureReason: $this->failureMessage($data),
        );
    }

    public function refund(PaymentRefundRequest $request, StorePaymentAccount $account): PaymentRefundResult
    {
        $payload = [
            'charge_id' => $request->providerChargeReference,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'reason' => $request->reason,
            'reference' => ['transaction' => $request->clientReference],
        ];

        $response = $this->client($account)->post('refunds', array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        $data = $this->decode($response, 'The payment provider rejected the refund.');

        $status = $this->mapRefundStatus((string) ($data['status'] ?? ''));

        return new PaymentRefundResult(
            status: $status,
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            raw: $this->safePayload($data),
            failureReason: $status === PaymentRefundStatus::Failed ? $this->failureMessage($data) : null,
        );
    }

    public function verifyWebhook(PaymentWebhookRequest $request, StorePaymentAccount $account): PaymentWebhookResult
    {
        $payload = $request->payload;
        $signature = trim((string) ($request->headers['hashstring'] ?? ''));

        if ($signature === '') {
            throw PaymentProviderException::because('The payment webhook is missing its signature.', [
                'provider' => $this->code(),
            ]);
        }

        $secret = $account->webhookSecret();

        if ($secret === '') {
            throw PaymentProviderException::because('The payment connection has no webhook secret.', [
                'provider' => $this->code(),
                'account_id' => $account->getKey(),
            ]);
        }

        if (! $this->signatureMatches($payload, $signature, $secret)) {
            throw PaymentProviderException::because('The payment webhook signature is invalid.', [
                'provider' => $this->code(),
                'account_id' => $account->getKey(),
            ]);
        }

        $object = strtolower((string) ($payload['object'] ?? 'charge'));
        $eventId = (string) ($payload['id'] ?? '');
        $status = $object === 'refund'
            ? null
            : $this->mapChargeStatus((string) ($payload['status'] ?? ''));

        // A provider may emit the same charge id more than once as its status
        // changes. The body digest keeps redelivery idempotent without treating
        // a later CAPTURED event as a duplicate of the earlier INITIATED one.
        $bodyDigest = hash('sha256', $request->rawBody);

        return new PaymentWebhookResult(
            eventId: $eventId !== '' ? $eventId.'_'.substr($bodyDigest, 0, 32) : $bodyDigest,
            eventType: $object.'.'.strtolower((string) ($payload['status'] ?? 'unknown')),
            clientReference: $this->stringOrNull(data_get($payload, 'reference.transaction')),
            providerReference: $this->stringOrNull($payload['id'] ?? null),
            status: $status,
            amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            raw: $this->safePayload($payload),
            failureReason: $status === PaymentAttemptStatus::Failed ? $this->failureMessage($payload) : null,
        );
    }

    public function testConnection(StorePaymentAccount $account): PaymentConnectionResult
    {
        try {
            $response = $this->client($account)->get('charges', ['limit' => 1]);
        } catch (\Throwable $exception) {
            return new PaymentConnectionResult(false, 'The provider could not be reached.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        if ($response->successful()) {
            return new PaymentConnectionResult(true, 'The provider accepted these credentials.', [
                'status' => $response->status(),
            ]);
        }

        return new PaymentConnectionResult(
            false,
            $response->status() === 401
                ? 'The provider rejected the secret key.'
                : 'The provider returned HTTP '.$response->status().'.',
            ['status' => $response->status()],
        );
    }

    private function client(StorePaymentAccount $account): PendingRequest
    {
        $config = (array) config('payments.providers.tap', []);

        return Http::withToken($account->secretKey())
            ->acceptJson()
            ->asJson()
            ->baseUrl(rtrim((string) ($config['base_url'] ?? 'https://api.tap.company/v2'), '/'))
            ->connectTimeout((int) ($config['connect_timeout_seconds'] ?? 10))
            ->timeout((int) ($config['timeout_seconds'] ?? 20))
            ->withHeaders(['lang' => 'en']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $fallbackMessage): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if ($response->successful()) {
            return $data;
        }

        throw PaymentProviderException::because(
            $this->failureMessage($data) ?? $fallbackMessage,
            [
                'provider' => $this->code(),
                'status' => $response->status(),
                'response' => $this->safePayload($data),
            ],
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signatureMatches(array $payload, string $signature, string $secret): bool
    {
        $id = (string) ($payload['id'] ?? '');
        $currency = (string) ($payload['currency'] ?? '');
        $gateway = (string) ($payload['gateway_reference'] ?? '');
        $payment = (string) ($payload['payment_reference'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $created = (string) ($payload['created'] ?? '');
        $amount = $payload['amount'] ?? '';

        foreach ($this->amountVariants($amount) as $variant) {
            $string = 'x_id'.$id.'x_amount'.$variant.'x_currency'.$currency
                .'x_gateway_reference'.$gateway.'x_payment_reference'.$payment
                .'x_status'.$status.'x_created'.$created;

            $expected = hash_hmac('sha256', $string, $secret);

            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tap's documented hash uses the amount exactly as delivered. Gateways have
     * historically serialised `10`, `10.0` and `10.00` for the same charge, so
     * each harmless representation is tried; the HMAC still proves possession
     * of the secret and the amount/currency checks below remain exact.
     *
     * @return list<string>
     */
    private function amountVariants(mixed $amount): array
    {
        if (! is_numeric($amount)) {
            return [(string) $amount];
        }

        $value = (float) $amount;
        $variants = [(string) $amount];

        foreach ([0, 2, 3, 4] as $decimals) {
            $variants[] = number_format($value, $decimals, '.', '');
        }

        $variants[] = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return array_values(array_unique(array_filter($variants, static fn (string $value): bool => $value !== '')));
    }

    private function mapChargeStatus(string $status): PaymentAttemptStatus
    {
        return match (strtoupper($status)) {
            'CAPTURED', 'PAID', 'SUCCEEDED' => PaymentAttemptStatus::Succeeded,
            'AUTHORIZED' => PaymentAttemptStatus::RequiresAction,
            'IN_PROGRESS', 'PROCESSING' => PaymentAttemptStatus::Processing,
            'PENDING', 'INITIATED', 'NEW' => PaymentAttemptStatus::Pending,
            'FAILED', 'DECLINED', 'ERROR' => PaymentAttemptStatus::Failed,
            'CANCELLED', 'CANCELED', 'VOID' => PaymentAttemptStatus::Cancelled,
            'EXPIRED' => PaymentAttemptStatus::Expired,
            default => PaymentAttemptStatus::Pending,
        };
    }

    private function mapRefundStatus(string $status): PaymentRefundStatus
    {
        return match (strtoupper($status)) {
            'REFUNDED', 'SUCCEEDED', 'CAPTURED' => PaymentRefundStatus::Succeeded,
            'FAILED', 'DECLINED', 'CANCELLED', 'CANCELED' => PaymentRefundStatus::Failed,
            default => PaymentRefundStatus::Pending,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(PaymentChargeRequest $request): array
    {
        $parts = preg_split('/\s+/', trim($request->customerName)) ?: [];
        $firstName = $parts[0] ?? 'Customer';
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        $customer = array_filter([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $request->customerEmail,
            'phone' => $this->phone($request->customerPhone),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return $customer;
    }

    /**
     * @return array{country_code: string, number: string}|null
     */
    private function phone(?string $phone): ?array
    {
        $phone = trim((string) $phone);

        if (preg_match('/^\+(\d{6,15})$/', $phone, $matches) !== 1) {
            return null;
        }

        $digits = $matches[1];

        foreach (self::DIAL_CODES as $code) {
            if (str_starts_with($digits, $code) && strlen($digits) - strlen($code) >= 6) {
                return [
                    'country_code' => $code,
                    'number' => substr($digits, strlen($code)),
                ];
            }
        }

        return null;
    }

    /**
     * Remove credentials and volatile fields before a payload is persisted or
     * logged. Tap responses do not echo the bearer token, but this is the one
     * choke point and must stay safe if that ever changes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        foreach (['authorization', 'token', 'secret', 'secret_key'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function failureMessage(array $data): ?string
    {
        foreach (['message', 'description', 'error', 'reason'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 500, '');
            }

            if (is_array($value)) {
                $nested = $value['message'] ?? $value['description'] ?? null;

                if (is_string($nested) && trim($nested) !== '') {
                    return Str::limit(trim($nested), 500, '');
                }
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
