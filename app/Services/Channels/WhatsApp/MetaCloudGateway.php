<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\WhatsAppAccountStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Channels\WhatsApp\Exceptions\WhatsAppDeliveryFailed;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta WhatsApp Cloud API implementation of {@see WhatsAppGateway}.
 *
 * The Graph API version and host come from `config('services.whatsapp')` so a
 * version sunset is a one-line environment change rather than a code release.
 * The access token is read from the encrypted model cast — falling back to the
 * platform token for the single configured number — and only ever travels in an
 * `Authorization` header. Sending a message is a state changing call, so it is
 * deliberately *not* retried: a duplicate WhatsApp message is visible to the
 * customer and cannot be unsent.
 */
class MetaCloudGateway implements WhatsAppGateway
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function sendText(Conversation $conversation, string $body, ?string $idempotencyKey = null): string
    {
        // A caller that supplies a stable key is asking for send-once semantics.
        // If the message is already in the thread, the previous attempt reached
        // the provider and repeated work would deliver it to the customer twice.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->getKey())
                ->where('direction', MessageDirection::Outbound)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof ConversationMessage && filled($existing->external_id)) {
                return (string) $existing->external_id;
            }
        }

        $account = $this->accountFor($conversation, 'send_text');

        $response = $this->client($account)->post($this->messagesUrl($account), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $conversation->channel_account,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);

        $messageId = $this->messageIdFrom($response, 'send_text');

        // The local message is only written after the provider accepted it, so
        // the inbox never shows a message that was never actually sent.
        $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
            'user_id' => auth()->id(),
            'type' => MessageType::Text,
            'body' => $body,
            'external_id' => $messageId,
            'idempotency_key' => $idempotencyKey,
            'delivery_status' => 'sent',
            'sent_at' => now(),
        ]);

        return $messageId;
    }

    public function sendTemplate(Conversation $conversation, string $templateName, string $languageCode, array $parameters = [], ?string $idempotencyKey = null): string
    {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->getKey())
                ->where('direction', MessageDirection::Outbound)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof ConversationMessage && filled($existing->external_id)) {
                return (string) $existing->external_id;
            }
        }

        $account = $this->accountFor($conversation, 'send_template');

        $response = $this->client($account)->post($this->messagesUrl($account), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $conversation->channel_account,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => [[
                    'type' => 'body',
                    'parameters' => collect($parameters)
                        ->map(fn (mixed $value): array => ['type' => 'text', 'text' => (string) $value])
                        ->all(),
                ]],
            ],
        ]);

        $messageId = $this->messageIdFrom($response, 'send_template');

        $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
            'user_id' => auth()->id(),
            'type' => MessageType::Template,
            'body' => $templateName,
            'external_id' => $messageId,
            'idempotency_key' => $idempotencyKey,
            'delivery_status' => 'sent',
            'sent_at' => now(),
            'meta' => [
                'template' => [
                    'name' => $templateName,
                    'language' => $languageCode,
                    'parameters' => $parameters,
                ],
            ],
        ]);

        return $messageId;
    }

    public function downloadMedia(WhatsappAccount $account, string $mediaId): array
    {
        if (blank($mediaId) || blank($this->tokenFor($account))) {
            throw WhatsAppDeliveryFailed::missingCredentials('download_media');
        }

        $metadata = $this->client($account)
            ->connectTimeout(3)
            ->timeout(10)
            ->get($this->baseUrl().'/'.$mediaId);

        if (! $metadata->successful()) {
            throw $this->failure('download_media', $metadata);
        }

        $url = (string) $metadata->json('url');
        $mimeType = (string) $metadata->json('mime_type');

        if (! $this->isAllowedMediaUrl($url)) {
            throw WhatsAppDeliveryFailed::fromProvider('download_media', $metadata->status(), 'Untrusted media URL', $metadata->header('x-fb-request-id'));
        }

        $download = Http::withToken((string) $this->tokenFor($account))
            ->timeout(60)
            ->get($url);

        if (! $download->successful()) {
            throw $this->failure('download_media', $download);
        }

        $contents = $download->body();
        $maxBytes = max(1, (int) config('services.whatsapp.media_max_bytes', 25 * 1024 * 1024));

        if (strlen($contents) > $maxBytes) {
            throw WhatsAppDeliveryFailed::fromProvider('download_media', 413, 'Media exceeds the allowed size', null);
        }

        return [
            'contents' => $contents,
            'mime_type' => $mimeType !== '' ? $mimeType : (string) $download->header('Content-Type'),
            'size' => strlen($contents),
            'filename' => $this->filenameFrom($download->header('Content-Disposition')),
        ];
    }

    private function isAllowedMediaUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $allowed = (array) config('services.whatsapp.media_hosts', [
            'lookaside.fbsbx.com',
            '*.fbcdn.net',
            '*.whatsapp.net',
        ]);

        foreach ($allowed as $pattern) {
            $pattern = strtolower((string) $pattern);

            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }

    private function filenameFrom(?string $header): ?string
    {
        if (blank($header)) {
            return null;
        }

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $header, $matches) !== 1) {
            return null;
        }

        $name = basename(urldecode($matches[1]));

        return $name !== '' ? $name : null;
    }

    public function verifyAccount(WhatsappAccount $account): string
    {
        if (blank($account->phone_number_id) || blank($this->tokenFor($account))) {
            throw WhatsAppDeliveryFailed::missingCredentials('verify_account');
        }

        $response = $this->client($account)
            ->connectTimeout(3)
            ->timeout(10)
            // Verification is idempotent, so a transient failure may be retried.
            ->retry([250, 1000], 0, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()))
            ->get($this->baseUrl().'/'.$account->phone_number_id);

        if (! $response->successful()) {
            throw $this->failure('verify_account', $response);
        }

        return (string) ($response->json('verified_name') ?? $account->name);
    }

    public function recordStatus(ConversationMessage $message, string $status, ?string $error = null): void
    {
        $this->conversations->markDelivery(
            $message->external_id === null ? [] : [$message->external_id],
            $status,
            $error,
        );
    }

    /**
     * Resolve the account behind a conversation and refuse to send without one.
     *
     * `conversations.channel_account` holds the *customer's* number — it is the
     * recipient, never a Meta identifier — so the account cannot be looked up
     * from it. It comes from `whatsapp_account_id`, which the webhook and the
     * conversation starter both record, falling back to the tenant's own
     * connection for threads opened before that column existed.
     */
    private function accountFor(Conversation $conversation, string $operation): WhatsappAccount
    {
        $connected = WhatsappAccount::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('status', WhatsAppAccountStatus::Connected);

        // A bound thread may only send through the number it belongs to: a
        // conversation is bound, falling back to a different connection would
        // answer the customer from a number they never wrote to.
        if ($conversation->whatsapp_account_id !== null) {
            $account = (clone $connected)->whereKey($conversation->whatsapp_account_id)->first();

            if ($account === null || ! $account->isUsable()) {
                throw WhatsAppDeliveryFailed::missingCredentials($operation);
            }

            return $account;
        }

        $account = (clone $connected)
            ->when(
                $conversation->store_id !== null,
                // An unbound thread may only send through its own store's
                // number, or through one the tenant left unassigned.
                fn ($query) => $query->where(function ($scoped) use ($conversation): void {
                    $scoped->where('store_id', $conversation->store_id)
                        ->orWhereNull('store_id');
                }),
            )
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($account === null || ! $account->isUsable()) {
            throw WhatsAppDeliveryFailed::missingCredentials($operation);
        }

        return $account;
    }

    /**
     * The access token for a number: its own, or the platform token when this
     * is the single number named in configuration. Never logged, never returned.
     */
    private function tokenFor(WhatsappAccount $account): ?string
    {
        if (filled($account->access_token)) {
            return (string) $account->access_token;
        }

        if ((string) config('services.whatsapp.phone_number_id') !== (string) $account->phone_number_id) {
            return null;
        }

        $token = config('services.whatsapp.cloud_api_token');

        return filled($token) ? (string) $token : null;
    }

    private function client(WhatsappAccount $account): PendingRequest
    {
        return Http::withToken((string) $this->tokenFor($account))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(15);
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
            throw $this->failure($operation, $response);
        }

        $messageId = $response->json('messages.0.id');

        if (! is_string($messageId) || $messageId === '') {
            throw WhatsAppDeliveryFailed::fromProvider($operation, $response->status(), 'Missing message id', $response->header('x-fb-request-id'));
        }

        return $messageId;
    }

    private function failure(string $operation, Response $response): WhatsAppDeliveryFailed
    {
        return WhatsAppDeliveryFailed::fromProvider(
            $operation,
            $response->status(),
            $response->json('error.message'),
            $response->header('x-fb-request-id'),
        );
    }
}
