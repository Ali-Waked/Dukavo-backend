<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use App\Models\ChannelWebhookEvent;
use App\Services\Channels\ChannelCapabilityRegistry;
use App\Services\Channels\ChannelConnectionManager;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Platform-wide integration health.
 *
 * This screen answers one question: which channels are available, which
 * tenant connections are failing, and what did a provider deliver that Dukavo
 * could not process? It is read-only apart from an explicit re-check, and it
 * never returns a tenant credential — only status, health and the failure
 * message a merchant can already see.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly ChannelConnectionManager $manager,
        private readonly TenantContext $tenants,
    ) {}

    public function index(): JsonResponse
    {
        $connections = ChannelConnection::query()->acrossTenants()->with('tenant')->get();
        $events = ChannelWebhookEvent::query()->acrossTenants();

        $channels = [];

        foreach (ChannelCapabilityRegistry::all() as $key => $definition) {
            $channelConnections = $connections->where('channel', $key);

            $channels[] = [
                'key' => $key,
                'name' => $definition['name'],
                'provider' => $definition['provider'],
                'availability' => $definition['availability'],
                'capabilities' => $definition['capabilities']->toArray(),
                'credential_fields' => $definition['credential_fields'],
                'notes' => $definition['notes'],
                'platform_configured' => $this->platformConfigured($key),
                'connections' => $channelConnections->count(),
                'connected' => $channelConnections->filter(
                    static fn (ChannelConnection $connection): bool => $connection->isUsable(),
                )->count(),
                'failing' => $channelConnections->filter(
                    static fn (ChannelConnection $connection): bool => $connection->health->value === 'failing',
                )->count(),
                'unprocessed_events' => (clone $events)
                    ->where('provider', $key)
                    ->whereNull('processed_at')
                    ->count(),
            ];
        }

        return ApiResponse::success('', ['channels' => $channels]);
    }

    public function show(string $channel): JsonResponse
    {
        $definition = ChannelCapabilityRegistry::get($channel);

        abort_if($definition === null, 404);

        $connections = ChannelConnection::query()
            ->acrossTenants()
            ->with('tenant')
            ->where('channel', $channel)
            ->orderBy('tenant_id')
            ->get()
            ->map(static fn (ChannelConnection $connection): array => [
                'id' => $connection->id,
                'tenant_id' => $connection->tenant_id,
                'tenant' => $connection->tenant?->name,
                'name' => $connection->name,
                'external_account_id' => $connection->external_account_id,
                'status' => $connection->status->value,
                'health' => $connection->health->value,
                'error_message' => $connection->error_message,
                'last_health_check_at' => $connection->last_health_check_at?->toIso8601String(),
            ]);

        $events = ChannelWebhookEvent::query()
            ->acrossTenants()
            ->where('provider', $channel)
            ->whereNull('processed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (ChannelWebhookEvent $event): array => [
                'id' => $event->id,
                'tenant_id' => $event->tenant_id,
                'event_type' => $event->event_type,
                'failure' => $event->failure,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success('', [
            'channel' => [
                'key' => $channel,
                'name' => $definition['name'],
                'provider' => $definition['provider'],
                'availability' => $definition['availability'],
                'capabilities' => $definition['capabilities']->toArray(),
                'credential_fields' => $definition['credential_fields'],
                'notes' => $definition['notes'],
                'platform_configured' => $this->platformConfigured($channel),
            ],
            'connections' => $connections,
            'unprocessed_events' => $events,
        ]);
    }

    /**
     * Re-test every connection of one channel across tenants.
     *
     * The action is deliberately explicit: it calls the provider, so it must be
     * a platform operator's decision rather than something a page load does.
     */
    public function recheck(string $channel): JsonResponse
    {
        abort_if(ChannelCapabilityRegistry::get($channel) === null, 404);

        $connections = ChannelConnection::query()
            ->acrossTenants()
            ->where('channel', $channel)
            ->get();

        $checked = 0;

        foreach ($connections as $connection) {
            try {
                $this->tenants->run($connection->tenant, function () use ($connection, &$checked): void {
                    $this->manager->test($connection);
                    $checked++;
                });
            } catch (Throwable) {
                // A single broken connection must not stop the sweep. Its
                // recorded error already explains the failure.
            }
        }

        return ApiResponse::success(sprintf('%d connection(s) re-checked.', $checked));
    }

    /**
     * Whether the platform-level app credentials for a channel are present.
     * Only a boolean leaves this method; the values stay in configuration.
     */
    private function platformConfigured(string $channel): bool
    {
        return match ($channel) {
            'whatsapp', 'instagram', 'messenger' => filled(config('services.meta.app_secret')),
            'telegram' => filled(config('services.telegram.webhook_secret')),
            'email' => filled(config('services.email.webhook_secret')),
            'sms' => filled(config('services.sms.webhook_secret')),
            'tiktok' => filled(config('services.tiktok.client_secret')),
            'x' => filled(config('services.x.api_secret')),
            default => false,
        };
    }
}
