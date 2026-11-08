<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Delivery receipts from the transactional email provider.
 *
 * The provider can tell us something a send call cannot: that a message was
 * accepted but later bounced, or that the recipient marked it as spam. Those
 * facts belong on the delivery row, not in a log line, so an operator can see
 * why a merchant never received a notice.
 *
 * The endpoint is deliberately generic. Dukavo has no provider-specific webhook
 * parser because no provider is configured yet; this accepts a normalized body
 * and a signed request, which is the part every provider agrees on. A payload
 * that cannot be matched to a delivery is acknowledged rather than refused, so a
 * provider does not redeliver it forever.
 */
class EmailWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (($refusal = $this->refuseUnsigned($request)) !== null) {
            return $refusal;
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return ApiResponse::success('Acknowledged.');
        }

        $messageId = (string) ($payload['message_id'] ?? $payload['provider_message_id'] ?? '');
        $event = strtolower((string) ($payload['event'] ?? $payload['type'] ?? ''));

        if ($messageId === '' || $event === '') {
            return ApiResponse::success('Acknowledged.');
        }

        $delivery = NotificationDelivery::query()
            ->where('provider_message_id', $messageId)
            ->first();

        if (! $delivery instanceof NotificationDelivery) {
            Log::info('Email webhook for an unknown provider message id.', ['message_id' => $messageId]);

            return ApiResponse::success('Acknowledged.');
        }

        if ($event === 'delivered') {
            $delivery->markDelivered($payload);

            return ApiResponse::success('Delivery recorded.');
        }

        $failureEvents = ['bounce', 'bounced', 'complaint', 'complained', 'failed', 'rejected', 'dropped'];

        if (in_array($event, $failureEvents, true)) {
            $reason = trim((string) ($payload['reason'] ?? $payload['description'] ?? $event));
            $permanent = $event !== 'failed'
                ? true
                : (bool) ($payload['permanent'] ?? false);

            $delivery->markProviderFailure(
                $reason !== '' ? $reason : $event,
                $permanent,
                $payload,
            );

            return ApiResponse::success('Failure recorded.');
        }

        // An unrecognised event is acknowledged without changing the evidence.
        return ApiResponse::success('Acknowledged.');
    }

    /**
     * Refuse a callback we cannot prove came from the provider.
     *
     * A delivery row is an operational record: letting an anonymous caller mark
     * an email as bounced would corrupt that record and could hide a real
     * delivery. The secret is therefore mandatory; if it is missing the
     * endpoint is closed rather than open.
     */
    private function refuseUnsigned(Request $request): ?JsonResponse
    {
        $secret = config('dukavo.email.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('Email webhook rejected: DUKAVO_EMAIL_WEBHOOK_SECRET is not configured.');

            return ApiResponse::error('Email webhook validation is not configured.', 403);
        }

        $header = (string) $request->header('X-Dukavo-Signature', '');
        $signature = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;

        if ($signature === '') {
            Log::warning('Email webhook rejected: no X-Dukavo-Signature header.');

            return ApiResponse::error('Missing email webhook signature.', 401);
        }

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Email webhook rejected: signature mismatch.');

            return ApiResponse::error('Invalid email webhook signature.', 401);
        }

        return null;
    }
}
