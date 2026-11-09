<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationDeliveryResource;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryRetryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Platform delivery monitoring.
 *
 * The screen is read-mostly: it shows what was attempted on every channel and
 * lets an operator retry a recorded transient failure. Provider acceptance
 * (`sent`) and an actual delivery receipt (`delivered`) stay distinct, and the
 * private message payload is never returned.
 */
class NotificationDeliveryController extends Controller
{
    public function __construct(private readonly NotificationDeliveryRetryService $retries) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(NotificationDeliveryStatus::class)],
            'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
            'event_key' => ['sometimes', 'string', 'max:120'],
            'notification_id' => ['sometimes', 'uuid'],
            'tenant_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:120'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = NotificationDelivery::query()
            ->with(['notification.tenant', 'recipient'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($validated['event_key'] ?? null, fn ($query, $event) => $query->where('event_key', $event))
            ->when($validated['notification_id'] ?? null, fn ($query, $id) => $query->where('notification_id', $id))
            ->when($validated['tenant_id'] ?? null, fn ($query, $tenantId) => $query
                ->whereHas('notification', fn ($inner) => $inner->where('tenant_id', $tenantId)))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $term = '%'.$search.'%';

                $query->where(fn ($inner) => $inner
                    ->where('recipient_ref', 'like', $term)
                    ->orWhere('event_key', 'like', $term)
                    ->orWhere('error', 'like', $term));
            })
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return NotificationDeliveryResource::collection($deliveries);
    }

    public function show(NotificationDelivery $delivery): NotificationDeliveryResource
    {
        return new NotificationDeliveryResource(
            $delivery->load(['notification.tenant', 'recipient']),
        );
    }

    public function retry(Request $request, NotificationDelivery $delivery): NotificationDeliveryResource
    {
        $delivery = $this->retries->retry($delivery, $request->user());

        return new NotificationDeliveryResource(
            $delivery->load(['notification.tenant', 'recipient']),
        );
    }
}
