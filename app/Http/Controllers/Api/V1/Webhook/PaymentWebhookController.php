<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Exceptions\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Services\Payments\Data\PaymentWebhookRequest;
use App\Services\Payments\PaymentWebhookService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider payment callbacks.
 *
 * No session, Sanctum token or tenant header is involved: the provider cannot
 * present any of them. The callback is recorded, its signature is verified with
 * the merchant connection that owns the referenced attempt, and only then may
 * it change payment state. An invalid signature is a 400 so the provider (or
 * the operator reading logs) sees the failure instead of a silent success.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentWebhookService $webhooks) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $payload = $request->json()->all();
        $payload = is_array($payload) ? $payload : [];

        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            $headers[strtolower((string) $key)] = (string) (is_array($values) ? ($values[0] ?? '') : $values);
        }

        $webhook = new PaymentWebhookRequest(
            payload: $payload,
            headers: $headers,
            rawBody: $request->getContent(),
        );

        try {
            $event = $this->webhooks->handle($provider, $webhook);
        } catch (PaymentProviderException $exception) {
            report($exception);

            return ApiResponse::error($exception->getMessage(), 400);
        }

        return ApiResponse::success('Callback received.', [
            'event_id' => $event->event_id,
            'status' => $event->status->value,
        ]);
    }
}
