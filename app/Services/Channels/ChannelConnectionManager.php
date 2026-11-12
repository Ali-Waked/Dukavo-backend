<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelHealth;
use App\Enums\WhatsAppAccountStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ChannelConnection;
use App\Models\WhatsappAccount;
use App\Services\Channels\Data\ConnectionTestResult;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Channels\WhatsApp\WhatsAppAccountService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The lifecycle of a tenant's channel connections.
 *
 * Controllers never touch credentials, statuses or connectors directly. This
 * manager is the only place that:
 *
 * - validates a channel against {@see ChannelCapabilityRegistry};
 * - accepts only the credential fields that channel declares, so an unknown key
 *   cannot be smuggled into the encrypted blob;
 * - stores the credential blob encrypted (the model cast does the encryption);
 * - tests a connection and records an honest status and health;
 * - keeps the existing WhatsApp account records and the generic connection rows
 *   in step, so WhatsApp keeps working exactly as before.
 *
 * A channel that awaits provider access can still store credentials — that is
 * how an operator prepares an integration — but it is never marked connected,
 * because the provider has not confirmed anything.
 */
final class ChannelConnectionManager
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ChannelProviderRegistry $connectors,
        private readonly WhatsAppAccountService $whatsapp,
    ) {}

    /**
     * The registry definitions annotated with this tenant's live connections.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $this->syncWhatsAppConnections();

        $connections = $this->connections()->groupBy('channel');
        $definitions = [];

        foreach (ChannelCapabilityRegistry::all() as $channel => $definition) {
            $definition['capabilities'] = $definition['capabilities']->toArray();
            $definition['connections'] = ($connections[$channel] ?? collect())->values()->all();
            $definition['connected_count'] = ($connections[$channel] ?? collect())
                ->filter(static fn (ChannelConnection $connection): bool => $connection->status === ChannelConnectionStatus::Connected)
                ->count();

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return Collection<int, ChannelConnection>
     */
    public function connections(): Collection
    {
        return ChannelConnection::query()
            ->with('store')
            ->orderBy('channel')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a connection and test it once.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function connect(array $attributes): ChannelConnection
    {
        $channel = (string) ($attributes['channel'] ?? '');

        if (! ChannelCapabilityRegistry::exists($channel)) {
            throw BusinessRuleViolation::because('Unknown channel.', ['channel' => $channel], 'unknown_channel');
        }

        if ($channel === 'whatsapp') {
            return $this->connectWhatsApp($attributes);
        }

        $credentials = $this->sanitiseCredentials($channel, (array) ($attributes['credentials'] ?? []));

        if ($channel === 'telegram' && blank($credentials['webhook_secret'] ?? null)) {
            $credentials['webhook_secret'] = Str::random(48);
        }

        $connection = ChannelConnection::query()->create([
            'store_id' => $attributes['store_id'] ?? null,
            'channel' => $channel,
            'provider' => ChannelCapabilityRegistry::get($channel)['provider'],
            'name' => (string) ($attributes['name'] ?? ChannelCapabilityRegistry::get($channel)['name']),
            'external_account_id' => $this->externalAccountId($channel, $credentials),
            'credentials' => $credentials,
            'capabilities' => ChannelCapabilityRegistry::capabilities($channel)->toArray(),
            'status' => ChannelConnectionStatus::Pending,
            'health' => ChannelHealth::Unknown,
            'meta' => $this->connectionMeta($channel, $credentials),
        ]);

        return $this->test($connection);
    }

    /**
     * Replace the supplied credential fields and re-test.
     *
     * A blank secret means "leave the stored value alone", so a merchant can
     * rename a connection or change a non-secret field without re-pasting a
     * token the UI never shows.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(ChannelConnection $connection, array $attributes): ChannelConnection
    {
        if ($connection->channel === 'whatsapp') {
            return $this->updateWhatsApp($connection, $attributes);
        }

        if (array_key_exists('store_id', $attributes)) {
            $connection->store_id = $attributes['store_id'];
        }

        if (array_key_exists('name', $attributes)) {
            $connection->name = (string) $attributes['name'];
        }

        $provided = (array) ($attributes['credentials'] ?? []);
        $merged = array_merge((array) $connection->credentials, $this->sanitiseCredentials($connection->channel, $provided, allowPartial: true));

        $connection->forceFill([
            'credentials' => $merged,
            'external_account_id' => $this->externalAccountId($connection->channel, $merged),
            'status' => ChannelConnectionStatus::Pending,
            'health' => ChannelHealth::Unknown,
            'error_message' => null,
            'meta' => $this->connectionMeta($connection->channel, $merged),
        ])->save();

        return $this->test($connection);
    }

    /**
     * Verify the credentials with the provider and record the outcome.
     *
     * A failed test is a recorded state, not an exception: the merchant needs
     * to see why the channel is not working and repair it. Only a missing
     * connector is exceptional, because that is a deployment bug.
     */
    public function test(ChannelConnection $connection): ChannelConnection
    {
        $connector = $this->connectors->for($connection->channel);

        try {
            $result = $connector->testConnection($connection);
        } catch (Throwable $exception) {
            $result = ConnectionTestResult::failure($exception->getMessage());
        }

        $this->applyResult($connection, $result);

        return $connection->refresh();
    }

    /**
     * Disconnect a channel. Credentials are erased; history is untouched.
     */
    public function disconnect(ChannelConnection $connection): ChannelConnection
    {
        if ($connection->channel === 'whatsapp') {
            $account = $this->whatsappAccount($connection);

            if ($account instanceof WhatsappAccount) {
                $this->whatsapp->disconnect($account);
            }
        }

        $connection->forceFill([
            'credentials' => null,
            'status' => ChannelConnectionStatus::Disconnected,
            'health' => ChannelHealth::Unknown,
            'error_message' => null,
        ])->save();

        return $connection->refresh();
    }

    /**
     * Reconnect a disconnected channel using the stored credentials.
     */
    public function reconnect(ChannelConnection $connection): ChannelConnection
    {
        if ($connection->channel === 'whatsapp') {
            $account = $this->whatsappAccount($connection);

            if ($account instanceof WhatsappAccount) {
                try {
                    $this->whatsapp->verify($account);
                } catch (Throwable $exception) {
                    $connection->forceFill([
                        'status' => ChannelConnectionStatus::Error,
                        'health' => ChannelHealth::Failing,
                        'error_message' => mb_substr($exception->getMessage(), 0, 500),
                        'last_health_check_at' => now(),
                    ])->save();

                    return $connection->refresh();
                }

                return $this->syncWhatsAppConnection($account);
            }

            throw ChannelDeliveryFailed::notConfigured('whatsapp');
        }

        if (blank($connection->credentials)) {
            throw BusinessRuleViolation::because(
                'This connection has no stored credentials. Enter them again to reconnect.',
                ['connection_id' => $connection->getKey()],
                'credentials_missing',
            );
        }

        $connection->forceFill(['status' => ChannelConnectionStatus::Pending])->save();

        return $this->test($connection);
    }

    /**
     * Mirror every existing WhatsApp account into a generic connection row.
     *
     * WhatsApp predates the channel layer and its accounts already work. Rather
     * than migrate or rewrite them, each account is projected into a connection
     * that carries `meta.whatsapp_account_id`; the WhatsApp connector then sends
     * through the mature gateway. The projection is idempotent.
     */
    public function syncWhatsAppConnections(): void
    {
        $tenant = $this->tenants->tenant();

        if ($tenant === null) {
            return;
        }

        $accounts = WhatsappAccount::query()
            ->orderBy('name')
            ->get();

        foreach ($accounts as $account) {
            $this->syncWhatsAppConnection($account);
        }
    }

    /**
     * The masked credential shape the UI may render. Values are never exposed;
     * only the field names and whether a value is stored.
     *
     * @return list<array{key: string, label: string, secret: bool, required: bool, help: string, configured: bool}>
     */
    public function credentialFields(ChannelConnection $connection): array
    {
        $definition = ChannelCapabilityRegistry::get($connection->channel);
        $stored = (array) $connection->credentials;

        return array_map(static fn (array $field): array => $field + [
            'configured' => filled($stored[$field['key']] ?? null),
        ], $definition['credential_fields'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connectWhatsApp(array $attributes): ChannelConnection
    {
        $account = $this->whatsapp->connect([
            'store_id' => $attributes['store_id'] ?? null,
            'name' => (string) ($attributes['name'] ?? 'WhatsApp'),
            'phone_number_id' => $attributes['phone_number_id'] ?? data_get($attributes, 'credentials.phone_number_id'),
            'business_account_id' => $attributes['business_account_id'] ?? data_get($attributes, 'credentials.business_account_id'),
            'access_token' => $attributes['access_token'] ?? data_get($attributes, 'credentials.access_token'),
            'webhook_verify_token' => $attributes['webhook_verify_token'] ?? data_get($attributes, 'credentials.webhook_verify_token'),
        ]);

        return $this->syncWhatsAppConnection($account);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateWhatsApp(ChannelConnection $connection, array $attributes): ChannelConnection
    {
        $account = $this->whatsappAccount($connection);

        if (! $account instanceof WhatsappAccount) {
            throw ChannelDeliveryFailed::notConfigured('whatsapp');
        }

        $this->whatsapp->update($account, array_filter([
            'store_id' => $attributes['store_id'] ?? null,
            'name' => $attributes['name'] ?? null,
            'phone_number_id' => data_get($attributes, 'credentials.phone_number_id'),
            'business_account_id' => data_get($attributes, 'credentials.business_account_id'),
            'access_token' => data_get($attributes, 'credentials.access_token'),
            'webhook_verify_token' => data_get($attributes, 'credentials.webhook_verify_token'),
        ], static fn (mixed $value): bool => $value !== null));

        return $this->syncWhatsAppConnection($account->refresh());
    }

    private function syncWhatsAppConnection(WhatsappAccount $account): ChannelConnection
    {
        $connection = ChannelConnection::query()
            ->where('channel', 'whatsapp')
            ->where(function ($query) use ($account): void {
                $query->where('meta->whatsapp_account_id', $account->getKey())
                    ->orWhere(function ($inner) use ($account): void {
                        $inner->whereNull('external_account_id')
                            ->where('name', $account->name);
                    });
            })
            ->first();

        $values = [
            'tenant_id' => $account->tenant_id,
            'store_id' => $account->store_id,
            'channel' => 'whatsapp',
            'provider' => 'meta_cloud',
            'name' => $account->name,
            'external_account_id' => $account->phone_number_id,
            'display_name' => data_get($account->meta, 'verified_name'),
            'credentials' => [],
            'capabilities' => ChannelCapabilityRegistry::capabilities('whatsapp')->toArray(),
            'status' => ChannelConnectionStatus::from($account->status->value),
            'health' => $account->status === WhatsAppAccountStatus::Connected
                ? ChannelHealth::Healthy
                : ChannelHealth::Unknown,
            'error_message' => $account->error_message,
            'connected_at' => $account->connected_at,
            'last_synced_at' => $account->last_synced_at,
            'meta' => ['whatsapp_account_id' => $account->getKey()],
        ];

        if ($connection instanceof ChannelConnection) {
            $connection->forceFill($values)->save();

            return $connection->refresh();
        }

        return ChannelConnection::query()->create($values);
    }

    private function whatsappAccount(ChannelConnection $connection): ?WhatsappAccount
    {
        $id = data_get($connection->meta, 'whatsapp_account_id');

        if (is_numeric($id)) {
            $account = WhatsappAccount::query()->whereKey((int) $id)->first();

            if ($account instanceof WhatsappAccount) {
                return $account;
            }
        }

        return WhatsappAccount::query()
            ->where('phone_number_id', (string) $connection->external_account_id)
            ->first();
    }

    private function applyResult(ChannelConnection $connection, ConnectionTestResult $result): void
    {
        $availability = ChannelCapabilityRegistry::get($connection->channel)['availability'] ?? null;
        $awaiting = $availability === ChannelCapabilityRegistry::AWAITING_PROVIDER_ACCESS;

        $connection->forceFill([
            'status' => $result->ok
                ? ChannelConnectionStatus::Connected
                : ($awaiting ? ChannelConnectionStatus::Pending : ChannelConnectionStatus::Error),
            'health' => $result->ok ? ChannelHealth::Healthy : ChannelHealth::Failing,
            'display_name' => $result->accountName ?? $connection->display_name,
            'error_message' => $result->ok ? null : mb_substr($result->message, 0, 500),
            'connected_at' => $result->ok ? ($connection->connected_at ?? now()) : $connection->connected_at,
            'last_health_check_at' => now(),
            'last_synced_at' => now(),
        ])->save();
    }

    /**
     * Keep only the credential fields the channel declares.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    private function sanitiseCredentials(string $channel, array $credentials, bool $allowPartial = false): array
    {
        $definition = ChannelCapabilityRegistry::get($channel);

        if ($definition === null) {
            throw BusinessRuleViolation::because('Unknown channel.', ['channel' => $channel], 'unknown_channel');
        }

        $allowed = array_column($definition['credential_fields'], 'key');
        $clean = [];

        foreach ($credentials as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw BusinessRuleViolation::because(
                    sprintf('The credential field "%s" is not valid for %s.', $key, $channel),
                    ['field' => $key, 'channel' => $channel],
                    'unknown_credential_field',
                );
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw BusinessRuleViolation::because(
                    sprintf('The credential field "%s" must be a string.', $key),
                    ['field' => $key],
                    'invalid_credential_field',
                );
            }

            $clean[$key] = (string) $value;
        }

        if (! $allowPartial) {
            $missing = array_values(array_filter(
                $definition['credential_fields'],
                static fn (array $field): bool => $field['required'] && ! isset($clean[$field['key']]),
            ));

            if ($missing !== []) {
                throw BusinessRuleViolation::because(
                    'Required credentials are missing: '.implode(', ', array_column($missing, 'label')).'.',
                    ['missing' => array_column($missing, 'key')],
                    'credentials_missing',
                );
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function externalAccountId(string $channel, array $credentials): ?string
    {
        $key = match ($channel) {
            'whatsapp' => 'phone_number_id',
            'instagram', 'messenger' => 'page_id',
            'email' => 'from_address',
            'sms' => 'sender_id',
            'tiktok' => 'client_key',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $value = $credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Non-secret connection metadata.
     *
     * Telegram authenticates webhooks with a secret token echoed verbatim, so
     * the connector needs to find the connection by that token. Only a hash is
     * stored, and the raw secret lives in the encrypted credential blob.
     *
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    private function connectionMeta(string $channel, array $credentials): array
    {
        if ($channel === 'telegram' && filled($credentials['webhook_secret'] ?? null)) {
            return ['webhook_secret_hash' => hash('sha256', (string) $credentials['webhook_secret'])];
        }

        return [];
    }
}
