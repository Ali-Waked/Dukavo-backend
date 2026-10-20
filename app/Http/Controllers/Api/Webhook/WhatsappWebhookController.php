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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Inbound Meta webhooks.
 *
 * Four rules govern this endpoint.
 *
 * 1. It must never leak a credential: the GET handshake echoes only the
 *    challenge, and only when the verify token matches the environment or a
 *    connected account.
 * 2. It must never be a write endpoint for whoever finds the URL: POST
 *    deliveries are accepted only when Meta's `X-Hub-Signature-256` HMAC over
 *    the raw body matches `WHATSAPP_APP_SECRET`, and refused otherwise.
 * 3. It must never fail a delivery: Meta retries anything that is not a 2xx, so
 *    an unexpected payload is logged and acknowledged with a 200.
 * 4. It must not trust the sender's claims: the tenant comes from the account
 *    whose `phone_number_id` Meta signed the call with, and every query afterwards
 *    runs inside that tenant's context.
 *
 * The handshake answer is deliberately plain text, not JSON: Meta compares the
 * response body against the challenge it sent, and a JSON envelope would never
 * match.
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
    public function verify(Request $request): Response
    {
        $token = $this->hubParameter($request, 'hub.verify_token');

        abort_unless(
            $this->hubParameter($request, 'hub.mode') === 'subscribe'
                && $token !== ''
                && $this->verifyTokenMatches($token),
            403,
        );

        return response($this->hubParameter($request, 'hub.challenge'), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Accept the handshake only for a token Meta and Dukavo both know.
     *
     * Two tokens are accepted, because there are two configurations worth
     * supporting: the environment-wide `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, which
     * covers an installation before any tenant has connected a number, and the
     * per-account token a merchant can rotate from the settings screen. The
     * scope is dropped explicitly because no tenant is resolved during the
     * handshake.
     */
    private function verifyTokenMatches(string $token): bool
    {
        $configured = config('services.whatsapp.webhook_verify_token');

        if (is_string($configured) && $configured !== '' && hash_equals($configured, $token)) {
            return true;
        }

        return WhatsappAccount::query()
            ->acrossTenants()
            ->where('webhook_verify_token', $token)
            ->exists();
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
        if (($refusal = $this->refuseUnsigned($request)) !== null) {
            return $refusal;
        }

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
     * Refuse a delivery Meta did not sign, or one we cannot check at all.
     *
     * Without this check the endpoint would be an unauthenticated write into any
     * merchant's inbox: a `phone_number_id` is not a secret, and posting a
     * payload shaped like Meta's would let a stranger create conversations and
     * customers inside a tenant they have no relationship with. The check fails
     * closed — a missing `WHATSAPP_APP_SECRET` refuses deliveries rather than
     * accepting them blind — because a silently disabled integration is a
     * configuration problem to fix, whereas a forged one is a security incident.
     *
     * Only the result is compared, never the secret, and the raw body is what
     * Meta signed: re-encoding the parsed payload would change its bytes.
     */
    private function refuseUnsigned(Request $request): ?JsonResponse
    {
        $secret = config('services.whatsapp.app_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('WhatsApp webhook rejected: WHATSAPP_APP_SECRET is not configured, so signatures cannot be verified.');

            return ApiResponse::error('WhatsApp signature validation is not configured.', 403);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            Log::warning('WhatsApp webhook rejected: no X-Hub-Signature-256 header.');

            return ApiResponse::error('Missing WhatsApp signature.', 401);
        }

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        if (! hash_equals($expected, substr($header, 7))) {
            Log::warning('WhatsApp webhook rejected: signature mismatch.');

            return ApiResponse::error('Invalid WhatsApp signature.', 401);
        }

        return null;
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
                [
                    // The thread belongs to the number it arrived on, so a reply
                    // cannot leave from a different connection — which matters the
                    // moment a tenant runs more than one number.
                    'whatsapp_account_id' => $account->getKey(),
                    'store_id' => $account->store_id,
                ],
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
