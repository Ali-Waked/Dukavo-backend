<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChannelConnection;
use App\Services\Channels\ChannelCapabilityRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel connection as the integration centre sees it.
 *
 * Credential *values* never appear here. The UI receives the declared fields
 * and whether each one is configured, which is enough to render the form and
 * explain what is missing without ever putting a secret in a response, a log
 * or the browser's memory.
 *
 * @property-read ChannelConnection $resource
 */
class ChannelConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = ChannelCapabilityRegistry::get($this->channel);
        $stored = (array) $this->credentials;

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'channel' => $this->channel,
            'provider' => $this->provider,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'external_account_id' => $this->external_account_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'health' => $this->health->value,
            'health_label' => $this->health->label(),
            'capabilities' => $this->capabilities ?? [],
            'credential_fields' => array_map(
                static fn (array $field): array => $field + [
                    'configured' => filled($stored[$field['key']] ?? null),
                ],
                $definition['credential_fields'] ?? [],
            ),
            'availability' => $definition['availability'] ?? null,
            'error_message' => $this->error_message,
            'webhook_url' => $this->channel === 'whatsapp'
                ? url('/api/v1/webhooks/whatsapp')
                : url(sprintf('/api/v1/webhooks/channels/%s/%s', $this->channel, $this->id)),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ] : null),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_health_check_at' => $this->last_health_check_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
