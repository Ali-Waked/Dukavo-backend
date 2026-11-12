<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\IntegrationHealthState;
use App\Enums\PaymentProviderStatus;
use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\IntegrationHealthCheck;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Services\Notifications\Push\Contracts\PushTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Explicit integration probes and their last recorded outcome.
 *
 * Reading the health page never makes a network call: it returns the last
 * recorded check, and an absent row means "not tested". Only an operator's
 * explicit test (or, in future, a scheduled check) runs a probe, so loading the
 * page cannot spend provider budget or rate-limit an integration.
 *
 * Every probe reports an honest state. "Configuration required" is distinct
 * from "disconnected" because the remedy is different, and "not tested" is not
 * a failure.
 */
final class IntegrationHealthService
{
    /**
     * @var array<string, array{label: string, label_ar: string, flag: ?string}>
     */
    private const INTEGRATIONS = [
        'ai' => ['label' => 'AI providers', 'label_ar' => 'مزودو الذكاء الاصطناعي', 'flag' => 'ai_assistant'],
        'email' => ['label' => 'Email delivery', 'label_ar' => 'تسليم البريد', 'flag' => 'email_notifications'],
        'whatsapp' => ['label' => 'WhatsApp Cloud API', 'label_ar' => 'واجهة واتساب السحابية', 'flag' => 'whatsapp'],
        'storage' => ['label' => 'Media storage', 'label_ar' => 'تخزين الوسائط', 'flag' => null],
        'payments' => ['label' => 'Payment providers', 'label_ar' => 'مزودو المدفوعات', 'flag' => 'online_payments'],
        'push' => ['label' => 'Push notifications', 'label_ar' => 'الإشعارات الفورية', 'flag' => 'push_notifications'],
    ];

    public function __construct(
        private readonly ConfigurationResolver $resolver,
        private readonly FeatureFlagService $features,
        private readonly PushTransport $push,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $checks = IntegrationHealthCheck::query()
            ->get()
            ->keyBy('integration');

        $integrations = [];

        foreach (self::INTEGRATIONS as $key => $definition) {
            /** @var IntegrationHealthCheck|null $check */
            $check = $checks->get($key);
            $disabled = $definition['flag'] !== null && $this->features->disabled($definition['flag']);
            $state = $disabled ? IntegrationHealthState::Disabled : ($check?->state ?? IntegrationHealthState::NotTested);

            $integrations[] = [
                'key' => $key,
                'label' => $definition['label'],
                'label_ar' => $definition['label_ar'],
                'flag' => $definition['flag'],
                'state' => $state->value,
                'state_label' => $state->label(),
                'tone' => $state->tone(),
                'summary' => $disabled
                    ? 'Disabled by the platform feature flag.'
                    : $check?->summary,
                'metadata' => $check?->metadata,
                'checked_at' => $check?->checked_at?->toIso8601String(),
                'failed_at' => $check?->failed_at?->toIso8601String(),
            ];
        }

        return $integrations;
    }

    /**
     * Run one probe and persist its outcome.
     *
     * @return array<string, mixed>
     */
    public function check(string $integration, ?User $actor = null): array
    {
        if (! array_key_exists($integration, self::INTEGRATIONS)) {
            throw new \InvalidArgumentException(sprintf('Unknown integration [%s].', $integration));
        }

        [$state, $summary, $metadata] = $this->probe($integration);

        $check = IntegrationHealthCheck::query()->updateOrCreate(
            ['integration' => $integration],
            [
                'state' => $state,
                'summary' => $summary,
                'metadata' => $metadata,
                'checked_at' => now(),
                'failed_at' => $state === IntegrationHealthState::Connected ? null : now(),
                'checked_by' => $actor?->getKey(),
            ],
        );

        return [
            'key' => $integration,
            'label' => self::INTEGRATIONS[$integration]['label'],
            'label_ar' => self::INTEGRATIONS[$integration]['label_ar'],
            'state' => $state->value,
            'state_label' => $state->label(),
            'tone' => $state->tone(),
            'summary' => $summary,
            'metadata' => $check->metadata,
            'checked_at' => $check->checked_at?->toIso8601String(),
            'failed_at' => $check->failed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probe(string $integration): array
    {
        try {
            return match ($integration) {
                'ai' => $this->probeAi(),
                'email' => $this->probeEmail(),
                'whatsapp' => $this->probeWhatsApp(),
                'storage' => $this->probeStorage(),
                'payments' => $this->probePayments(),
                'push' => $this->probePush(),
            };
        } catch (Throwable $exception) {
            return [
                IntegrationHealthState::Unavailable,
                'The probe failed unexpectedly. Check the application log for details.',
                ['error' => class_basename($exception)],
            ];
        }
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probeAi(): array
    {
        if ($this->features->disabled('ai_assistant')) {
            return [IntegrationHealthState::Disabled, 'Disabled by the platform feature flag.', []];
        }

        $providers = AiProvider::query()->where('enabled', true)->count();
        $credentials = AiProviderCredential::query()
            ->whereNull('tenant_id')
            ->whereNull('revoked_at')
            ->whereNotNull('api_key')
            ->count();

        $metadata = ['enabled_providers' => $providers, 'platform_credentials' => $credentials];

        if ($providers === 0) {
            return [IntegrationHealthState::ConfigurationRequired, 'No AI provider is enabled. Configure one in AI administration.', $metadata];
        }

        if ($credentials === 0) {
            return [IntegrationHealthState::ConfigurationRequired, 'Providers are enabled but no platform credential is stored.', $metadata];
        }

        return [
            IntegrationHealthState::Connected,
            sprintf('%d enabled provider(s) and %d platform credential(s) are available.', $providers, $credentials),
            $metadata,
        ];
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probeEmail(): array
    {
        if ($this->features->disabled('email_notifications')) {
            return [IntegrationHealthState::Disabled, 'Disabled by the platform feature flag.', []];
        }

        $mailer = (string) config('mail.default', 'log');
        $metadata = ['mailer' => $mailer];

        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            return [IntegrationHealthState::Disabled, sprintf('The %s driver is a development sink and does not deliver mail.', $mailer), $metadata];
        }

        if ($mailer !== 'smtp') {
            return [
                IntegrationHealthState::NotTested,
                sprintf('The %s driver cannot be probed without sending a real message.', $mailer),
                $metadata,
            ];
        }

        $host = $this->resolver->get('email.host');
        $port = $this->resolver->get('email.port');
        $metadata['host'] = $host->value;
        $metadata['port'] = $port->value;

        if (! $host->configured || ! is_string($host->value) || $host->value === '') {
            return [IntegrationHealthState::ConfigurationRequired, 'An SMTP host is required before mail can be sent.', $metadata];
        }

        $connection = @fsockopen(
            (string) $host->value,
            (int) ($port->value ?: 587),
            $errorCode,
            $errorMessage,
            3,
        );

        if ($connection === false) {
            $metadata['error_code'] = $errorCode;
            $metadata['error'] = $errorMessage;

            return [
                IntegrationHealthState::Disconnected,
                sprintf('Could not reach %s:%s.', $host->value, $port->value ?: 587),
                $metadata,
            ];
        }

        fclose($connection);

        return [
            IntegrationHealthState::Connected,
            sprintf('SMTP host %s:%s is reachable. Credentials are not verified by this TCP probe.', $host->value, $port->value ?: 587),
            $metadata,
        ];
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probeWhatsApp(): array
    {
        if ($this->features->disabled('whatsapp')) {
            return [IntegrationHealthState::Disabled, 'Disabled by the platform feature flag.', []];
        }

        $required = ['whatsapp.app_secret', 'whatsapp.cloud_api_token', 'whatsapp.phone_number_id'];
        $missing = $this->missing($required);

        if ($missing !== []) {
            return [
                IntegrationHealthState::ConfigurationRequired,
                'Platform WhatsApp credentials are incomplete.',
                ['missing' => $missing],
            ];
        }

        $token = $this->resolver->get('whatsapp.cloud_api_token')->value;
        $phoneNumberId = $this->resolver->get('whatsapp.phone_number_id')->value;
        $baseUrl = rtrim((string) config('services.whatsapp.api_base_url', 'https://graph.facebook.com'), '/');
        $version = (string) config('services.whatsapp.cloud_api_version', 'v26.0');
        $url = sprintf('%s/%s/%s', $baseUrl, $version, $phoneNumberId);

        $metadata = ['phone_number_id' => $phoneNumberId, 'api_version' => $version];

        try {
            $response = Http::withToken((string) $token)
                ->acceptJson()
                ->timeout(5)
                ->get($url, ['fields' => 'id']);
        } catch (ConnectionException $exception) {
            return [IntegrationHealthState::Disconnected, 'The Graph API could not be reached.', $metadata + ['error' => class_basename($exception)]];
        }

        $status = $response->status();

        return match (true) {
            $status === 200 => [IntegrationHealthState::Connected, 'The Graph API accepted the platform token for this phone number ID.', $metadata],
            in_array($status, [401, 403], true) => [IntegrationHealthState::InvalidCredentials, 'The Graph API rejected the platform token.', $metadata + ['http_status' => $status]],
            $status === 429 => [IntegrationHealthState::RateLimited, 'The Graph API rate-limited the probe. Try again later.', $metadata + ['http_status' => $status]],
            $status >= 500 => [IntegrationHealthState::Unavailable, 'The Graph API returned a server error.', $metadata + ['http_status' => $status]],
            default => [IntegrationHealthState::Disconnected, sprintf('The Graph API answered HTTP %d.', $status), $metadata + ['http_status' => $status]],
        };
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probeStorage(): array
    {
        $driver = (string) config('filesystems.default', 'local');
        $metadata = ['driver' => $driver];

        if ($driver === 'local') {
            return [IntegrationHealthState::Connected, 'Media is stored on this server; no external service is involved.', $metadata];
        }

        if ($driver !== 's3') {
            return [IntegrationHealthState::NotTested, sprintf('The %s disk has no automated probe.', $driver), $metadata];
        }

        $missing = $this->missing(['storage.s3_key', 'storage.s3_secret', 'storage.s3_bucket']);

        if ($missing !== []) {
            return [IntegrationHealthState::ConfigurationRequired, 'The S3-compatible disk is missing credentials or a bucket.', ['missing' => $missing] + $metadata];
        }

        $path = 'dukavo-health/'.Str::uuid().'.txt';

        try {
            $disk = Storage::disk('s3');
            $disk->put($path, 'dukavo-health-check');
            $exists = $disk->exists($path);
            $disk->delete($path);
        } catch (Throwable $exception) {
            return [
                IntegrationHealthState::Disconnected,
                'The S3-compatible disk rejected the test write.',
                $metadata + ['error' => class_basename($exception)],
            ];
        }

        return $exists
            ? [IntegrationHealthState::Connected, 'The configured bucket accepted a write, read and delete.', $metadata]
            : [IntegrationHealthState::Disconnected, 'The bucket write completed but the object could not be read back.', $metadata];
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probePayments(): array
    {
        if ($this->features->disabled('online_payments')) {
            return [IntegrationHealthState::Disabled, 'Disabled by the platform feature flag.', []];
        }

        $offered = PaymentProvider::query()
            ->where('integration_enabled', true)
            ->where('status', PaymentProviderStatus::Active->value)
            ->count();

        $metadata = ['offered_providers' => $offered];

        if ($offered === 0) {
            return [IntegrationHealthState::ConfigurationRequired, 'No payment provider adapter is currently offered.', $metadata];
        }

        return [
            IntegrationHealthState::Connected,
            sprintf('%d payment provider(s) are offered. Merchant accounts remain per store.', $offered),
            $metadata,
        ];
    }

    /**
     * @return array{0: IntegrationHealthState, 1: ?string, 2: array<string, mixed>}
     */
    private function probePush(): array
    {
        if ($this->features->disabled('push_notifications')) {
            return [IntegrationHealthState::Disabled, 'Disabled by the platform feature flag.', []];
        }

        if (! $this->push->isConfigured()) {
            return [IntegrationHealthState::ConfigurationRequired, 'Firebase credentials or the push channel are not configured for this deployment.', []];
        }

        return [IntegrationHealthState::Connected, 'Firebase Cloud Messaging credentials are configured.', []];
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function missing(array $keys): array
    {
        $missing = [];

        foreach ($keys as $key) {
            if (! $this->resolver->get($key)->configured) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
