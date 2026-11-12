<?php

declare(strict_types=1);

namespace App\Services\Channels\Connectors;

use App\Enums\MessageType;
use App\Models\ChannelConnection;
use App\Models\Conversation;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email channel over the platform mail transport.
 *
 * Inbound email is accepted only through the signed email webhook; the channel
 * never polls a mailbox. Outbound mail is sent through Laravel's configured
 * mailer so no SMTP credential is ever stored twice.
 */
final class EmailConnector implements ChannelConnector
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function channel(): string
    {
        return 'email';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            text: true, image: true, document: true, audio: false, voice: false,
            video: false, template: false, deliveryStatus: true, readStatus: false,
            mediaDownload: false,
        );
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        $secret = $connection?->credentials['webhook_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            $secret = config('services.email.webhook_secret');
        }

        return $this->verifier->sharedSecret($request, is_string($secret) ? $secret : null);
    }

    public function resolveConnection(Request $request, array $payload): ?ChannelConnection
    {
        $routeConnection = $request->route('connection');

        if ($routeConnection instanceof ChannelConnection) {
            return $routeConnection->channel === 'email' ? $routeConnection : null;
        }

        return null;
    }

    public function parseInbound(array $payload, ChannelConnection $connection): array
    {
        $from = (string) ($payload['from'] ?? $payload['sender'] ?? '');
        $messageId = (string) ($payload['message_id'] ?? $payload['id'] ?? '');

        if ($from === '' || $messageId === '') {
            return [];
        }

        return [
            new NormalizedInboundMessage(
                channel: 'email',
                externalAccountId: (string) $connection->external_account_id,
                externalMessageId: 'email:'.$messageId,
                senderId: $from,
                senderName: isset($payload['from_name']) ? (string) $payload['from_name'] : null,
                type: MessageType::Text,
                body: isset($payload['text']) ? (string) $payload['text'] : (isset($payload['body']) ? strip_tags((string) $payload['body']) : null),
                timestamp: isset($payload['timestamp']) ? (int) $payload['timestamp'] : null,
                meta: [
                    'provider' => 'email',
                    'subject' => $payload['subject'] ?? null,
                    'message_id' => $messageId,
                ],
            ),
        ];
    }

    public function parseStatus(array $payload, ChannelConnection $connection): array
    {
        $messageId = (string) ($payload['message_id'] ?? $payload['id'] ?? '');

        if ($messageId === '') {
            return [];
        }

        $status = strtolower((string) ($payload['status'] ?? 'delivered'));

        return [
            new NormalizedStatusUpdate(
                channel: 'email',
                externalMessageId: 'email:'.$messageId,
                status: in_array($status, ['delivered', 'read', 'failed', 'sent'], true) ? $status : 'delivered',
                failureReason: isset($payload['reason']) ? (string) $payload['reason'] : null,
                timestamp: isset($payload['timestamp']) ? (int) $payload['timestamp'] : null,
            ),
        ];
    }

    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        $recipient = trim((string) $conversation->channel_account);

        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ChannelDeliveryFailed::fromProvider('email', 'invalid_recipient', 'The conversation has no valid email address.');
        }

        $fromAddress = (string) ($connection->credentials['from_address'] ?? config('mail.from.address'));
        $fromName = (string) ($connection->credentials['from_name'] ?? config('mail.from.name'));

        if ($fromAddress === '') {
            throw ChannelDeliveryFailed::notConfigured('email');
        }

        $subject = (string) ($message->meta['subject'] ?? 'Message from '.config('app.name'));
        $body = (string) $message->body;

        try {
            Mail::raw($body, function ($mail) use ($recipient, $subject, $fromAddress, $fromName, $message): void {
                $mail->to($recipient)->subject($subject)->from($fromAddress, $fromName);

                if ($message->media !== null) {
                    $mail->attachData($message->media->contents, $message->media->filename, [
                        'mime' => $message->media->mimeType,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            throw ChannelDeliveryFailed::fromProvider('email', 'send_failed', 'The email could not be sent: '.$exception->getMessage());
        }

        return new SentMessage('email:'.Str::uuid()->toString());
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        $fromAddress = (string) ($connection->credentials['from_address'] ?? config('mail.from.address'));

        if ($fromAddress === '' || ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return ConnectionTestResult::failure('A valid from address is required.');
        }

        return ConnectionTestResult::success('Mail transport is configured for '.$fromAddress.'.', $fromAddress);
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        throw ChannelDeliveryFailed::unsupported('email', 'media download');
    }

    public function managesOwnPersistence(): bool
    {
        return false;
    }
}
