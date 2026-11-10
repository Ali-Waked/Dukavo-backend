<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentConnectionStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\PaymentProvider;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use Illuminate\Support\Carbon;

/**
 * Stores and verifies a merchant's provider credentials.
 *
 * Credentials are encrypted by the model cast and never leave the connection
 * row. A connection is only `active` after the provider itself accepted the
 * key; saving a key is not proof that it works, so the status starts `pending`
 * and is settled by {@see self::verify()}.
 */
class PaymentConnectionService
{
    public function __construct(private readonly PaymentProviderManager $providers) {}

    /**
     * @param  array{secret_key: string, public_key?: string|null}  $credentials
     */
    public function connect(
        Store $store,
        PaymentProvider $provider,
        array $credentials,
        string $environment = 'sandbox',
        ?string $webhookSecret = null,
        ?string $merchantReference = null,
    ): StorePaymentAccount {
        if (! $provider->isOffered()) {
            throw BusinessRuleViolation::because($provider->name.' is not open for merchant connections yet.');
        }

        $environment = strtolower(trim($environment));

        if (! in_array($environment, ['sandbox', 'live'], true)) {
            throw BusinessRuleViolation::because('The payment environment must be sandbox or live.');
        }

        $secretKey = trim((string) ($credentials['secret_key'] ?? ''));

        if ($secretKey === '') {
            throw BusinessRuleViolation::because('A provider secret key is required.');
        }

        $account = StorePaymentAccount::withTrashed()
            ->where('store_id', $store->getKey())
            ->where('provider_id', $provider->getKey())
            ->where('environment', $environment)
            ->first();

        if (! $account instanceof StorePaymentAccount) {
            $account = new StorePaymentAccount([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->getKey(),
                'provider_id' => $provider->getKey(),
                'environment' => $environment,
            ]);
        }

        if ($account->trashed()) {
            $account->restore();
        }

        $account->forceFill([
            'status' => PaymentConnectionStatus::Pending,
            'credentials' => array_filter([
                'secret_key' => $secretKey,
                'public_key' => isset($credentials['public_key']) ? trim((string) $credentials['public_key']) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'webhook_secret' => $webhookSecret !== null && trim($webhookSecret) !== ''
                ? trim($webhookSecret)
                : null,
            'merchant_reference' => $merchantReference !== null && trim($merchantReference) !== ''
                ? trim($merchantReference)
                : null,
            'last_verified_at' => null,
            'last_checked_at' => null,
            'last_error' => null,
        ])->save();

        return $this->verify($account);
    }

    public function verify(StorePaymentAccount $account): StorePaymentAccount
    {
        $provider = $account->provider()->first();

        if (! $provider instanceof PaymentProvider) {
            throw BusinessRuleViolation::because('The payment provider is no longer available.');
        }

        $result = $this->providers->adapterFor($provider)->testConnection($account);

        $account->forceFill([
            'status' => $result->ok ? PaymentConnectionStatus::Active : PaymentConnectionStatus::Disabled,
            'last_verified_at' => $result->ok ? Carbon::now() : null,
            'last_checked_at' => Carbon::now(),
            'last_error' => $result->ok ? null : $result->message,
        ])->save();

        return $account->refresh();
    }

    public function disable(StorePaymentAccount $account): StorePaymentAccount
    {
        $account->forceFill([
            'status' => PaymentConnectionStatus::Disabled,
            'last_checked_at' => Carbon::now(),
        ])->save();

        return $account->refresh();
    }

    public function revoke(StorePaymentAccount $account): void
    {
        $account->forceFill([
            'status' => PaymentConnectionStatus::Revoked,
            'last_checked_at' => Carbon::now(),
        ])->save();

        $account->delete();
    }
}
