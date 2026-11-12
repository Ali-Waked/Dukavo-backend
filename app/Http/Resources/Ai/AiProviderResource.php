<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiProvider;
use App\Services\Ai\Providers\CredentialSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider row as the AI admin dashboard sees it.
 *
 * `configuration` answers the only question an operator actually has —
 * "will this provider serve requests?" — without ever including the key.
 * Its source is `database` when a platform credential exists, else
 * `environment` when the historical bootstrap variable is set, else null
 * ("not configured").
 *
 * @property-read AiProvider $resource
 */
class AiProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolved = app(CredentialSource::class)->resolve($this->resource, tenant: null, byokEnabled: false, tenantOptIn: false);

        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'driver' => $this->resource->driver,
            'enabled' => (bool) $this->resource->enabled,
            'priority' => (int) $this->resource->priority,
            'notes' => $this->resource->notes,
            'metadata' => $this->resource->metadata ?? [],
            'configuration' => [
                'source' => $resolved['source'] ?? null,
                'model' => $resolved['model'] ?? null,
            ],
            'platform_credential' => $this->whenLoaded('platformCredential', fn () => $this->resource->platformCredential !== null
                ? new AiProviderCredentialResource($this->resource->platformCredential)
                : null),
            'models' => AiModelResource::collection($this->whenLoaded('models')),
            'models_count' => $this->whenCounted('models'),
            'routes_count' => $this->whenCounted('routes'),
            'credentials_count' => $this->whenCounted('credentials'),
            'health' => $this->whenLoaded('health', fn () => $this->resource->health !== null ? [
                'state' => $this->resource->health->state->value,
                'allows_calls' => $this->resource->health->allowsCalls(),
                'consecutive_failures' => (int) $this->resource->health->consecutive_failures,
                'failure_count' => (int) $this->resource->health->failure_count,
                'opened_at' => $this->resource->health->opened_at?->toIso8601String(),
                'cooldown_until' => $this->resource->health->cooldown_until?->toIso8601String(),
                'last_error_code' => $this->resource->health->last_error_code,
                'last_error_at' => $this->resource->health->last_error_at?->toIso8601String(),
                'last_success_at' => $this->resource->health->last_success_at?->toIso8601String(),
                'last_latency_ms' => (int) $this->resource->health->last_latency_ms,
            ] : null),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
