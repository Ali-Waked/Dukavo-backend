<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiTaskRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One hop of a task's provider chain.
 *
 * @property-read AiTaskRoute $resource
 */
class AiTaskRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'task_id' => $this->resource->task_id,
            'provider_id' => $this->resource->provider_id,
            'tenant_id' => $this->resource->tenant_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->resource->provider ? [
                'id' => $this->resource->provider->id,
                'key' => $this->resource->provider->key,
                'name' => $this->resource->provider->name,
                'enabled' => (bool) $this->resource->provider->enabled,
            ] : null),
            'model' => $this->resource->model,
            'priority' => (int) $this->resource->priority,
            'is_fallback' => (bool) $this->resource->is_fallback,
            'max_output_tokens' => $this->resource->max_output_tokens,
            'temperature' => $this->resource->temperature !== null ? (float) $this->resource->temperature : null,
            'timeout_seconds' => (int) $this->resource->timeout_seconds,
            'max_retries' => (int) $this->resource->max_retries,
        ];
    }
}
