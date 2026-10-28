<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Webhooks\WhatsAppWebhookProcessor;
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
        private readonly WhatsAppWebhookProcessor $processor,
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
        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? [];

                if (! is_array($value)) {
                    continue;
                }

                $account = $this->resolveAccount($value);

                if ($account === null) {
                    continue;
                }

                $this->tenants->run($account->tenant, function () use ($account, $value): void {
                    $this->processor->process($account, $value);
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
}
