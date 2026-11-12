<?php

declare(strict_types=1);

namespace App\Services\Channels\Connectors;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\WhatsAppAccountStatus;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\WhatsappAccount;
use App\Services\Channels\Contracts\ChannelConnector;
use App\Services\Channels\Data\ChannelCapabilities;
use App\Services\Channels\Data\ConnectionTestResult;
use App\Services\Channels\Data\DownloadedMedia;
use App\Services\Channels\Data\NormalizedInboundMessage;
use App\Services\Channels\Data\NormalizedStatusUpdate;
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Data\SentMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Channels\WebhookSignatureVerifier;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Channels\WhatsApp\Exceptions\WhatsAppDeliveryFailed;
use App\Services\Channels\WhatsApp\Webhooks\InboundWhatsAppMessage;
use App\Services\Channels\WhatsApp\Webhooks\WhatsAppDeliveryReceipt;
use App\Services\Channels\WhatsApp\Webhooks\WhatsAppWebhookParser;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Cloud API behind the channel contract.
 *
 * Sending still goes through the mature {@see WhatsAppGateway}: it owns the
 * send-once rule and writes the conversation row only after Meta accepts the
 * message. This connector adds the uniform capability, test and media-send
 * surface the omnichannel engine needs, without rewriting working behaviour.
 */
final class WhatsAppConnector implements ChannelConnector
{
    public function __construct(
        private readonly WhatsAppGateway $gateway,
        private readonly WhatsAppWebhookParser $parser,
        private readonly WebhookSignatureVerifier $verifier,
    ) {}

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            text: true, image: true, document: true, audio: true, voice: true,
            video: true, template: true, deliveryStatus: true, readStatus: true,
            mediaDownload: true,
        );
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        return $this->verifier->meta($request, (string) config('services.whatsapp.app_secret', ''));
    }

    public function resolveConnection(Request $request, array $payload): ?ChannelConnection
    {
        $phoneNumberId = (string) ($payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '');

        if ($phoneNumberId === '') {
            return null;
        }

        return ChannelConnection::query()
            ->acrossTenants()
            ->where('channel', 'whatsapp')
            ->where('external_account_id', $phoneNumberId)
            ->first();
    }

    public function parseInbound(array $payload, ChannelConnection $connection): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if (! is_array($value)) {
                    continue;
                }

                foreach ($this->parser->parse($value) as $event) {
                    if (! $event instanceof InboundWhatsAppMessage) {
                        continue;
                    }

                    $messages[] = new NormalizedInboundMessage(
                        channel: 'whatsapp',
                        externalAccountId: (string) $connection->external_account_id,
                        externalMessageId: $event->messageId,
                        senderId: $event->from,
                        senderName: $event->profileName,
                        type: $event->type,
                        body: $event->body,
                        mediaId: $event->mediaId,
                        mediaMimeType: $event->mediaMime,
                        mediaFilename: $event->mediaFilename,
                        timestamp: $event->timestamp,
                        meta: $event->meta,
                    );
                }
            }
        }

        return $messages;
    }

    public function parseStatus(array $payload, ChannelConnection $connection): array
    {
        $updates = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if (! is_array($value)) {
                    continue;
                }

                foreach ($this->parser->parse($value) as $event) {
                    if (! $event instanceof WhatsAppDeliveryReceipt) {
                        continue;
                    }

                    $updates[] = new NormalizedStatusUpdate(
                        channel: 'whatsapp',
                        externalMessageId: $event->messageId,
                        status: $event->state,
                        failureCode: $event->failureCode,
                        failureReason: $event->failureReason,
                        timestamp: $event->timestamp,
                    );
                }
            }
        }

        return $updates;
    }

    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        $account = $this->accountFor($conversation, $connection);

        try {
            if ($message->type === MessageType::Template) {
                $id = $this->gateway->sendTemplate(
                    $conversation,
                    (string) $message->templateName,
                    (string) $message->templateLanguage,
                    $message->templateParameters,
                );

                return new SentMessage($id);
            }

            if ($message->type === MessageType::Text) {
                return new SentMessage($this->gateway->sendText($conversation, (string) $message->body));
            }

            return $this->sendMedia($account, $conversation, $message);
        } catch (WhatsAppDeliveryFailed $exception) {
            throw ChannelDeliveryFailed::fromProvider('whatsapp', (string) $exception->errorCode(), $exception->getMessage());
        }
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        $account = $this->accountForConnection($connection);

        if ($account === null) {
            return ConnectionTestResult::failure('No WhatsApp account is linked to this connection.');
        }

        try {
            $name = $this->gateway->verifyAccount($account);

            return ConnectionTestResult::success('WhatsApp credentials are valid.', $name);
        } catch (Throwable $exception) {
            return ConnectionTestResult::failure($exception->getMessage());
        }
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        $account = $this->accountForConnection($connection);

        if ($account === null) {
            throw ChannelDeliveryFailed::notConfigured('whatsapp');
        }

        try {
            $media = $this->gateway->downloadMedia($account, $mediaId);

            return new DownloadedMedia(
                contents: $media['contents'],
                mimeType: $media['mime_type'],
                size: $media['size'],
                filename: $media['filename'] ?? null,
            );
        } catch (WhatsAppDeliveryFailed $exception) {
            throw ChannelDeliveryFailed::fromProvider('whatsapp', (string) $exception->errorCode(), $exception->getMessage());
        }
    }

    public function managesOwnPersistence(): bool
    {
        return true;
    }

    /**
     * Upload bytes to Meta and send the resulting media id.
     */
    private function sendMedia(WhatsappAccount $account, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        $media = $message->media;

        if ($media === null) {
            throw ChannelDeliveryFailed::fromProvider('whatsapp', 'missing_media', 'No media was supplied for this message.');
        }

        $token = $this->tokenFor($account);

        if ($token === null) {
            throw ChannelDeliveryFailed::notConfigured('whatsapp');
        }

        $upload = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(60)
            ->attach('file', $media->contents, $media->filename, ['Content-Type' => $media->mimeType])
            ->post($this->baseUrl().'/'.$account->phone_number_id.'/media', [
                'messaging_product' => 'whatsapp',
                'type' => $media->mimeType,
            ]);

        $mediaId = $upload->json('id');

        if (! is_string($mediaId) || $mediaId === '') {
            throw ChannelDeliveryFailed::fromProvider('whatsapp', (string) $upload->status(), (string) ($upload->json('error.message') ?? 'Media upload failed.'));
        }

        $type = match ($message->type) {
            MessageType::Image => 'image',
            MessageType::Video => 'video',
            MessageType::Audio => 'audio',
            default => 'document',
        };

        $payload = [$type => ['id' => $mediaId]];

        if ($message->body !== null && in_array($type, ['image', 'video', 'document'], true)) {
            $payload[$type]['caption'] = $message->body;
        }

        if ($type === 'document' && $media->filename !== '') {
            $payload[$type]['filename'] = $media->filename;
        }

        $response = $this->graphPost($account, $this->messagesUrl($account), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $conversation->channel_account,
            'type' => $type,
        ] + $payload);

        $messageId = $this->messageIdFrom($response, 'send_media');

        // The gateway persists text/template sends; media is persisted here so
        // the inbox sees exactly what Meta accepted.
        $conversation->messages()->create([
            'user_id' => auth()->id(),
            'direction' => MessageDirection::Outbound,
            'type' => $message->type,
            'body' => $message->body,
            'external_id' => $messageId,
            'delivery_status' => 'sent',
            'sent_at' => now(),
            'meta' => ['media' => ['provider_media_id' => $mediaId, 'mime_type' => $media->mimeType, 'filename' => $media->filename]],
        ]);

        return new SentMessage($messageId);
    }

    private function accountFor(Conversation $conversation, ChannelConnection $connection): WhatsappAccount
    {
        if ($conversation->whatsapp_account_id !== null) {
            $account = WhatsappAccount::query()->whereKey($conversation->whatsapp_account_id)->first();

            if ($account instanceof WhatsappAccount && $account->isUsable()) {
                return $account;
            }
        }

        $account = $this->accountForConnection($connection);

        if ($account === null) {
            throw ChannelDeliveryFailed::notConfigured('whatsapp');
        }

        return $account;
    }

    private function accountForConnection(ChannelConnection $connection): ?WhatsappAccount
    {
        $accountId = $connection->meta['whatsapp_account_id'] ?? null;

        if (is_int($accountId) || (is_string($accountId) && ctype_digit($accountId))) {
            $account = WhatsappAccount::query()->whereKey((int) $accountId)->first();

            if ($account instanceof WhatsappAccount) {
                return $account;
            }
        }

        return WhatsappAccount::query()
            ->where('phone_number_id', (string) $connection->external_account_id)
            ->where('status', WhatsAppAccountStatus::Connected)
            ->first();
    }

    private function tokenFor(WhatsappAccount $account): ?string
    {
        if (filled($account->access_token)) {
            return (string) $account->access_token;
        }

        if ((string) config('services.whatsapp.phone_number_id') === (string) $account->phone_number_id) {
            $token = config('services.whatsapp.cloud_api_token');

            return filled($token) ? (string) $token : null;
        }

        return null;
    }

    private function graphPost(WhatsappAccount $account, string $url, array $payload): Response
    {
        return Http::withToken((string) $this->tokenFor($account))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->post($url, $payload);
    }

    private function messagesUrl(WhatsappAccount $account): string
    {
        return sprintf('%s/%s/messages', $this->baseUrl(), $account->phone_number_id);
    }

    private function baseUrl(): string
    {
        $version = (string) (config('services.whatsapp.cloud_api_version') ?: 'v26.0');
        $host = rtrim((string) (config('services.whatsapp.api_base_url') ?: 'https://graph.facebook.com'), '/');

        return sprintf('%s/%s', $host, ltrim($version, '/'));
    }

    private function messageIdFrom(Response $response, string $operation): string
    {
        if (! $response->successful()) {
            throw ChannelDeliveryFailed::fromProvider('whatsapp', (string) $response->status(), (string) ($response->json('error.message') ?? 'WhatsApp rejected the message.'));
        }

        $messageId = $response->json('messages.0.id');

        if (! is_string($messageId) || $messageId === '') {
            throw ChannelDeliveryFailed::fromProvider('whatsapp', 'missing_message_id', 'WhatsApp did not return a message id.');
        }

        return $messageId;
    }
}
