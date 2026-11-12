<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A routed task and its platform policy.
 *
 * Limits of `0` mean "no explicit cap"; the legacy feature configuration
 * continues to apply, which is why they are passed through verbatim.
 *
 * @property-read AiTask $resource
 */
class AiTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'enabled' => (bool) $this->resource->enabled,
            'daily_per_user' => (int) $this->resource->daily_per_user,
            'daily_per_tenant' => (int) $this->resource->daily_per_tenant,
            'monthly_per_tenant' => (int) $this->resource->monthly_per_tenant,
            'monthly_platform_requests' => (int) $this->resource->monthly_platform_requests,
            'max_output_tokens' => $this->resource->max_output_tokens,
            'temperature' => $this->resource->temperature !== null ? (float) $this->resource->temperature : null,
            'timeout_seconds' => $this->resource->timeout_seconds,
            'max_retries' => $this->resource->max_retries,
            'description' => $this->resource->description,
            'routes' => AiTaskRouteResource::collection($this->whenLoaded('routes')),
            'prompt_templates_count' => $this->whenCounted('promptTemplates'),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
