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
 * The access token is read from the encrypted model cast and only ever travels in
 * an `Authorization` header. Sending a message is a state changing call, so it is
 * deliberately *not* retried: a duplicate WhatsApp message is visible to the
 * customer and cannot be unsent.
 */
class MetaCloudGateway implements WhatsAppGateway
{
    private const GRAPH_VERSION = 'v21.0';

    public function __construct(private readonly ConversationService $conversations) {}

    public function sendText(Conversation $conversation, string $body, ?string $externalId = null): string
    {
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
            'external_id' => $externalId ?? $messageId,
            'delivery_status' => 'sent',
            'sent_at' => now(),
        ]);

        return $messageId;
    }

    public function sendTemplate(Conversation $conversation, string $templateName, string $languageCode, array $parameters = []): string
    {
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

        return $this->messageIdFrom($response, 'send_template');
    }

    public function verifyAccount(WhatsappAccount $account): string
    {
        if (blank($account->phone_number_id) || blank($account->access_token)) {
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
     */
    private function accountFor(Conversation $conversation, string $operation): WhatsappAccount
    {
        $account = WhatsappAccount::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where(function ($query) use ($conversation): void {
                $query->where('phone_number_id', $conversation->channel_account)
                    ->orWhere('business_account_id', $conversation->channel_account);
            })
            ->where('status', WhatsAppAccountStatus::Connected)
            ->first();

        if ($account === null || ! $account->isUsable()) {
            throw WhatsAppDeliveryFailed::missingCredentials($operation);
        }

        return $account;
    }

    private function client(WhatsappAccount $account): PendingRequest
    {
        return Http::withToken($account->access_token)
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
        return sprintf('https://graph.facebook.com/%s', self::GRAPH_VERSION);
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
