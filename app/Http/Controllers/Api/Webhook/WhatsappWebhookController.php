<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhook;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WhatsappAccount;
use App\Services\Conversations\ConversationService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound Meta webhooks.
 *
 * Three rules govern this endpoint.
 *
 * 1. It must never leak a credential: the GET handshake echoes only the
 *    challenge, and only when the verify token matches a connected account.
 * 2. It must never fail a delivery: Meta retries anything that is not a 2xx, so
 *    an unexpected payload is logged and acknowledged with a 200.
 * 3. It must not trust the sender's claims: the tenant comes from the account
 *    whose `phone_number_id` Meta signed the call with, and every query afterwards
 *    runs inside that tenant's context.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Meta's verification handshake.
     */
    public function verify(Request $request): JsonResponse
    {
        $token = $this->hubParameter($request, 'hub.verify_token');

        abort_unless(
            $token !== ''
                // No tenant is resolved during the handshake, and the token must
                // be matched across accounts, so the scope is dropped explicitly.
                && WhatsappAccount::query()
                    ->acrossTenants()
                    ->where('webhook_verify_token', $token)
                    ->exists(),
            403,
        );

        return response()->json(['hub.challenge' => $this->hubParameter($request, 'hub.challenge')]);
    }

    /**
     * Read a Meta query parameter.
     *
     * Meta documents these as `hub.verify_token`, but PHP rewrites the dots in
     * query string keys to underscores during parsing, so the parameter actually
     * arrives as `hub_verify_token`. Both spellings are accepted rather than
     * failing the handshake for a reason no amount of retrying will fix.
     */
    private function hubParameter(Request $request, string $name): string
    {
        $value = $request->query($name);

        if ($value === null || $value === '') {
            $value = $request->query(str_replace('.', '_', $name));
        }

        return (string) $value;
    }

    /**
     * Status receipts and inbound customer messages.
     */
    public function events(Request $request): JsonResponse
    {
        try {
            $this->handle($request->all());
        } catch (\Throwable $exception) {
            // A 500 here makes Meta redeliver the same payload forever, so the
            // failure is recorded and the delivery acknowledged.
            Log::error('WhatsApp webhook failed.', ['message' => $exception->getMessage()]);
        }

        return ApiResponse::success('Acknowledged.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $account = $this->resolveAccount($value);

                if ($account === null) {
                    continue;
                }

                $this->tenants->run($account->tenant, function () use ($account, $value): void {
                    if ($value['statuses'] ?? []) {
                        $this->handleStatuses($value);
                    }

                    if ($value['messages'] ?? []) {
                        $this->handleInboundMessages($account, $value);
                    }
                });
            }
        }
    }

    /**
     * The tenant is derived from the connected account, never from the payload's
     * own contents.
     *
     * @param  array<string, mixed>  $value
     */
    private function resolveAccount(array $value): ?WhatsappAccount
    {
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');

        if ($phoneNumberId === '') {
            Log::warning('WhatsApp webhook without a phone number id.');

            return null;
        }

        $account = WhatsappAccount::query()
            ->acrossTenants()
            ->with('tenant')
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if ($account === null) {
            Log::warning('WhatsApp webhook for an unconnected phone number id.', [
                'phone_number_id' => $phoneNumberId,
            ]);
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleStatuses(array $value): void
    {
        foreach ($value['statuses'] as $status) {
            $state = $this->deliveryState((string) ($status['status'] ?? ''));

            $this->conversations->markDelivery(
                array_filter([(string) ($status['id'] ?? '')]),
                $state,
                $status['errors'][0]['title'] ?? null,
            );
        }
    }

    /**
     * Translate Meta's receipt into a delivery state.
     *
     * Meta sends a name (`sent`, `delivered`, `read`, `failed`) and older
     * documentation describes numeric codes for the same four. Anything
     * unrecognised is treated as `sent` rather than `failed`: inventing a
     * failure for a receipt this code does not understand would show the
     * merchant messages the provider never actually rejected.
     */
    private function deliveryState(string $status): string
    {
        return match (strtolower($status)) {
            '2', 'delivered' => 'delivered',
            '3', 'read' => 'read',
            'failed' => 'failed',
            default => 'sent',
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleInboundMessages(WhatsappAccount $account, array $value): void
    {
        foreach ($value['messages'] as $message) {
            $customer = $this->resolveCustomer($account, $message);

            $conversation = $this->conversations->openForCustomer(
                $customer,
                'whatsapp',
                (string) ($message['from'] ?? ''),
            );

            $this->conversations->addMessage($conversation, MessageDirection::Inbound, [
                'external_id' => (string) ($message['id'] ?? ''),
                'type' => $this->messageType($message),
                'body' => $this->messageBody($message),
                'meta' => ['provider' => 'meta', 'raw_type' => $message['type'] ?? 'unknown'],
            ]);
        }
    }

    /**
     * Match the sender to a known customer by phone number, and create the
     * customer when the merchant has never seen the number before.
     *
     * @param  array<string, mixed>  $message
     */
    private function resolveCustomer(WhatsappAccount $account, array $message): Customer
    {
        $phone = (string) ($message['from'] ?? '');

        $customer = Customer::query()->where('phone', $phone)->first();

        if ($customer !== null) {
            return $customer;
        }

        return Customer::query()->create([
            'name' => (string) ($message['profile']['name'] ?? $phone),
            'phone' => $phone,
            'status' => 'active',
            'store_id' => $account->store_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageType(array $message): MessageType
    {
        return match ((string) ($message['type'] ?? 'text')) {
            'image' => MessageType::Image,
            'audio', 'voice' => MessageType::Audio,
            'document', 'sticker' => MessageType::File,
            default => MessageType::Text,
        };
    }

    /**
     * Only text and captions become a body. Media with no caption stores a null
     * body rather than a placeholder string, so an image message is never shown
     * in the thread as if it contained words.
     *
     * @param  array<string, mixed>  $message
     */
    private function messageBody(array $message): ?string
    {
        $type = (string) ($message['type'] ?? 'text');

        if ($type === 'text') {
            return (string) ($message['text']['body'] ?? '');
        }

        $caption = $message[$type]['caption'] ?? null;

        return $caption === null ? null : (string) $caption;
    }
}
