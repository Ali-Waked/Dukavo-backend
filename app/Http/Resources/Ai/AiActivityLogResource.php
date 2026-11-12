<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One provider attempt.
 *
 * Metadata only, by design: the activity log never contains prompts, model
 * answers or credential material, so this resource has nothing to leak even
 * if it is exposed more widely than intended.
 *
 * @property-read AiActivityLog $resource
 */
class AiActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'correlation_id' => $this->resource->correlation_id,
            'tenant_id' => $this->resource->tenant_id,
            'user_id' => $this->resource->user_id,
            'task' => $this->resource->task,
            'feature' => $this->resource->feature,
            'provider' => $this->resource->provider,
            'model' => $this->resource->model,
            'status' => $this->resource->status,
            'error_category' => $this->resource->error_category?->value,
            'http_status' => $this->resource->http_status,
            'attempt' => (int) $this->resource->attempt,
            'fallback_attempts' => (int) $this->resource->fallback_attempts,
            'duration_ms' => (int) $this->resource->duration_ms,
            'input_tokens' => $this->resource->input_tokens,
            'output_tokens' => $this->resource->output_tokens,
            'cache_hit' => (bool) $this->resource->cache_hit,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
