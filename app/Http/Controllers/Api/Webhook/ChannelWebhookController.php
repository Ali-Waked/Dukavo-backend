<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhook;

use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use App\Models\ChannelWebhookEvent;
use App\Services\Channels\ChannelCapabilityRegistry;
use App\Services\Channels\ChannelMediaService;
use App\Services\Channels\ChannelProviderRegistry;
use App\Services\Channels\Contracts\ChannelConnector;
use App\Services\Channels\CustomerIdentityResolver;
use App\Services\Channels\Data\NormalizedInboundMessage;
use App\Services\Channels\Data\NormalizedStatusUpdate;
use App\Services\Channels\MessageNormalizer;
use App\Services\Conversations\ConversationService;
use App\Services\Voice\VoiceMessageRegistrar;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The generic inbound webhook for every channel in the connector registry.
 *
 * The controller is deliberately thin: the connector verifies the signature and
 * translates the payload, the identity resolver finds the customer, and the
 * conversation service writes the message. Three rules are absolute:
 *
 * 1. the tenant is derived from the verified connection record, never from an
 *    id in the payload;
 * 2. a delivery is acknowledged even when processing fails, because a provider
 *    that retries a bug in our code forever will never fix the bug — the ledger
 *    row keeps the failure visible;
 * 3. a signature that cannot be verified refuses the delivery.
 */
class ChannelWebhookController extends Controller
{
    public function __construct(
        private readonly ChannelProviderRegistry $connectors,
        private readonly MessageNormalizer $normalizer,
        private readonly CustomerIdentityResolver $identities,
        private readonly ConversationService $conversations,
        private readonly ChannelMediaService $media,
        private readonly VoiceMessageRegistrar $voices,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Meta's verification handshake, for the Meta-backed channels.
     */
    public function verify(Request $request, string $channel): Response
    {
        abort_unless(ChannelCapabilityRegistry::exists($channel), 404);
        abort_unless(in_array($channel, ['whatsapp', 'instagram', 'messenger'], true), 404);

        $token = $this->hubParameter($request, 'hub.verify_token');
        $mode = $this->hubParameter($request, 'hub.mode');
        $challenge = $this->hubParameter($request, 'hub.challenge');

        abort_unless($mode === 'subscribe' && $token !== '' && $this->verifyTokenMatches($channel, $token), 403);

        return response($challenge, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Provider deliveries.
     */
    public function events(Request $request, string $channel, ?string $connection = null): JsonResponse
    {
        $definition = ChannelCapabilityRegistry::get($channel);

        if ($definition === null) {
            return ApiResponse::error('Unknown channel.', 404);
        }

        if (! $this->connectors->has($channel)) {
            return ApiResponse::error('This channel is not available on this installation.', 404);
        }

        $payload = $request->all();
        $resolved = $this->resolveConnection($request, $channel, $connection, $payload);

        if (! $resolved instanceof ChannelConnection) {
            // Do not reveal whether a connection id exists. A provider will
            // retry, which is the correct behaviour for a misconfigured URL.
            return ApiResponse::error('No matching connection.', 404);
        }

        $connector = $this->connectors->for($channel);

        if (! $connector->verifyWebhook($request, $resolved)) {
            Log::warning('Channel webhook rejected: invalid signature.', [
                'channel' => $channel,
                'connection_id' => $resolved->getKey(),
            ]);

            return ApiResponse::error('Invalid signature.', 401);
        }

        try {
            $this->tenants->run($resolved->tenant, function () use ($connector, $resolved, $payload): void {
                $this->handle($connector, $resolved, $payload);
            });
        } catch (Throwable $exception) {
            // Acknowledge anyway; the ledger keeps the failure for retry.
            Log::error('Channel webhook failed.', [
                'channel' => $channel,
                'connection_id' => $resolved->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }

        return ApiResponse::success('Acknowledged.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(
        ChannelConnector $connector,
        ChannelConnection $connection,
        array $payload,
    ): void {
        foreach ($this->normalizer->inbound($connector, $payload, $connection) as $message) {
            $this->claimAndProcess($connection, $message->eventKey(), 'message', function () use ($connector, $connection, $message): void {
                $this->handleMessage($connector, $connection, $message);
            });
        }

        foreach ($this->normalizer->status($connector, $payload, $connection) as $update) {
            $this->claimAndProcess($connection, $update->eventKey(), 'status', function () use ($update): void {
                $this->handleStatus($update);
            });
        }
    }

    private function handleMessage(
        ChannelConnector $connector,
        ChannelConnection $connection,
        NormalizedInboundMessage $event,
    ): void {
        $customer = $this->identities->resolve($event, $connection);
        $conversation = $this->conversations->openForCustomer(
            $customer,
            $event->channel,
            $event->senderId,
            [
                'store_id' => $connection->store_id,
                'display_name' => $event->senderName,
                'phone' => in_array($event->channel, ['whatsapp', 'sms'], true) ? $event->senderId : null,
                'meta' => ['connection_id' => $connection->getKey()],
            ],
        );

        $meta = $event->meta;

        if ($event->isMedia() && $connector->capabilities()->mediaDownload) {
            try {
                $meta['media'] = $this->media->storeInbound($connection, $conversation, $event);

                if ($event->mediaFilename !== null) {
                    $meta['media']['filename'] = $event->mediaFilename;
                }
            } catch (Throwable $exception) {
                // The text is still valuable when the attachment cannot be
                // stored. The inbox renders an explicit unavailable attachment.
                $meta['media_error'] = 'Attachment could not be downloaded.';

                Log::warning('Channel media could not be stored.', [
                    'channel' => $connection->channel,
                    'conversation_id' => $conversation->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $message = $this->conversations->addMessage($conversation, MessageDirection::Inbound, [
            'external_id' => $event->externalMessageId,
            'type' => $event->type,
            'body' => $event->body,
            'meta' => $meta,
            'sent_at' => $event->timestamp !== null
                ? CarbonImmutable::createFromTimestampUTC($event->timestamp)
                : now(),
        ]);

        $this->voices->register($message);
    }

    private function handleStatus(NormalizedStatusUpdate $update): void
    {
        $this->conversations->markDelivery(
            [$update->externalMessageId],
            $update->status,
            $update->failureReason,
            $update->failureCode,
        );
    }

    /**
     * Claim a provider event exactly once and run it.
     *
     * The claim is the idempotency boundary. A processed event is a no-op; an
     * unprocessed one may be retried by a provider redelivery.
     */
    private function claimAndProcess(ChannelConnection $connection, string $eventKey, string $type, callable $callback): void
    {
        $claim = ChannelWebhookEvent::query()->firstOrCreate(
            [
                'tenant_id' => $connection->tenant_id,
                'provider' => $connection->channel,
                'event_key' => $eventKey,
            ],
            [
                'event_type' => $type,
                'payload_hash' => hash('sha256', $eventKey),
            ],
        );

        if (! $claim->wasRecentlyCreated && $claim->isProcessed()) {
            return;
        }

        try {
            $callback();
            $claim->forceFill(['processed_at' => now(), 'failure' => null])->save();
        } catch (Throwable $exception) {
            $claim->forceFill(['failure' => Str::limit($exception->getMessage(), 2000, '')])->save();

            Log::error('Channel webhook event failed.', [
                'channel' => $connection->channel,
                'event_key' => $eventKey,
                'connection_id' => $connection->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveConnection(Request $request, string $channel, ?string $connection, array $payload): ?ChannelConnection
    {
        if ($connection !== null && $connection !== '') {
            $model = ChannelConnection::query()->acrossTenants()->whereKey($connection)->first();

            return $model instanceof ChannelConnection && $model->channel === $channel ? $model : null;
        }

        return $this->connectors->for($channel)->resolveConnection($request, $payload);
    }

    private function verifyTokenMatches(string $channel, string $token): bool
    {
        $configured = config('services.meta.webhook_verify_token');

        if (is_string($configured) && $configured !== '' && hash_equals($configured, $token)) {
            return true;
        }

        if ($channel === 'whatsapp') {
            $whatsapp = config('services.whatsapp.webhook_verify_token');

            if (is_string($whatsapp) && $whatsapp !== '' && hash_equals($whatsapp, $token)) {
                return true;
            }
        }

        return ChannelConnection::query()
            ->acrossTenants()
            ->where('channel', $channel)
            ->get()
            ->contains(fn (ChannelConnection $connection): bool => hash_equals(
                (string) ($connection->credentials['webhook_verify_token'] ?? ''),
                $token,
            ));
    }

    private function hubParameter(Request $request, string $name): string
    {
        $value = $request->query($name);

        if ($value === null || $value === '') {
            $value = $request->query(str_replace('.', '_', $name));
        }

        return (string) $value;
    }
}
