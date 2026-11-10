<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only webhook evidence for platform operators.
 *
 * The payload is the provider's callback after credential-shaped fields were
 * removed. There is no merchant credential in this table, and no action here
 * can replay a callback or move money.
 */
class PaymentWebhookEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = PaymentWebhookEvent::query()
            ->with('provider')
            ->when($request->query('provider_id') !== null, fn ($query) => $query->where('provider_id', (int) $request->query('provider_id')))
            ->when($request->query('status') !== null, fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->query('payment_id') !== null, fn ($query) => $query->where('payment_id', (int) $request->query('payment_id')))
            ->when($request->query('tenant_id') !== null, fn ($query) => $query->where('tenant_id', (int) $request->query('tenant_id')))
            ->latest('id')
            ->paginate((int) min(100, max(1, (int) $request->query('per_page', 25))));

        return ApiResponse::success('', [
            'events' => collect($events->items())->map(fn (PaymentWebhookEvent $event): array => $this->payload($event))->values()->all(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }

    public function show(PaymentWebhookEvent $event): JsonResponse
    {
        $event->loadMissing('provider');

        return ApiResponse::success('', $this->payload($event));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentWebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'provider' => [
                'code' => $event->provider?->code,
                'name' => $event->provider?->name,
            ],
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'status' => $event->status->value,
            'signature_valid' => (bool) $event->signature_valid,
            'payment_id' => $event->payment_id,
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'failure_reason' => $event->failure_reason,
            'payload' => $event->payload,
            'processed_at' => $event->processed_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
