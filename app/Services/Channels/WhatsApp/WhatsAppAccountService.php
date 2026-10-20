<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp;

use App\Enums\WhatsAppAccountStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connecting and disconnecting tenant WhatsApp accounts.
 *
 * Credentials arrive from the merchant, are stored encrypted, and are never
 * returned by the API. An account only becomes `connected` once the provider has
 * confirmed it, so the inbox never routes through an account that cannot send.
 */
class WhatsAppAccountService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WhatsAppGateway $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function connect(array $attributes): WhatsappAccount
    {
        $tenant = $this->tenants->required();

        return DB::transaction(function () use ($tenant, $attributes): WhatsappAccount {
            $account = WhatsappAccount::query()->create([
                'tenant_id' => $tenant->getKey(),
                'store_id' => $this->resolveStoreId($attributes['store_id'] ?? null),
                'name' => $attributes['name'],
                'phone_number_id' => $attributes['phone_number_id'] ?? null,
                'business_account_id' => $attributes['business_account_id'] ?? null,
                'access_token' => $attributes['access_token'] ?? null,
                // Meta's handshake is configured by hand in the Meta dashboard, so
                // an account without one could never receive anything. Generate it
                // unless the merchant supplied their own.
                'webhook_verify_token' => $attributes['webhook_verify_token'] ?? Str::random(48),
                'status' => WhatsAppAccountStatus::Pending,
                'meta' => $attributes['meta'] ?? null,
            ]);

            // Verification is the last step: a wrong token must not leave the
            // account looking healthy.
            $this->verify($account);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(WhatsappAccount $account, array $attributes): WhatsappAccount
    {
        if (array_key_exists('store_id', $attributes)) {
            $attributes['store_id'] = $this->resolveStoreId($attributes['store_id']);
        }

        $account->fill($attributes)->save();

        if ($this->credentialsChanged($account, $attributes)) {
            $account->forceFill(['status' => WhatsAppAccountStatus::Pending])->save();
            $this->verify($account);
        }

        return $account;
    }

    /**
     * Confirm with the provider that the credentials work, and record the result.
     *
     * The account needs a phone number id and a token — its own, or the platform
     * token from configuration when this is the number that token belongs to.
     * Anything less is refused before Meta is called, so the account is left in a
     * clear `error` state rather than a misleading one.
     */
    public function verify(WhatsappAccount $account): WhatsappAccount
    {
        if (! $account->hasUsableCredentials()) {
            $account->forceFill([
                'status' => WhatsAppAccountStatus::Error,
                'error_message' => 'A phone number id and an access token are required.',
            ])->save();

            throw BusinessRuleViolation::because('A phone number id and an access token are required.');
        }

        try {
            $verifiedName = $this->gateway->verifyAccount($account);
        } catch (Throwable $exception) {
            $account->forceFill([
                'status' => WhatsAppAccountStatus::Error,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            throw $exception;
        }

        $account->forceFill([
            'status' => WhatsAppAccountStatus::Connected,
            'error_message' => null,
            'connected_at' => now(),
            'last_synced_at' => now(),
            'meta' => array_merge($account->meta ?? [], ['verified_name' => $verifiedName]),
        ])->save();

        return $account;
    }

    /**
     * Disconnect, keeping the record and its message history intact.
     */
    public function disconnect(WhatsappAccount $account): WhatsappAccount
    {
        $account->forceFill([
            'status' => WhatsAppAccountStatus::Disconnected,
            'access_token' => null,
            'error_message' => null,
        ])->save();

        return $account;
    }

    public function delete(WhatsappAccount $account): void
    {
        $account->delete();
    }

    private function credentialsChanged(WhatsappAccount $account, array $attributes): bool
    {
        $watched = ['phone_number_id', 'business_account_id', 'access_token'];

        return collect($watched)->contains(fn (string $key): bool => array_key_exists($key, $attributes));
    }

    private function resolveStoreId(mixed $storeId): ?int
    {
        if ($storeId === null || $storeId === '') {
            return null;
        }

        return Store::query()->find($storeId)?->getKey()
            ?? throw BusinessRuleViolation::because('The selected store does not exist.');
    }
}
