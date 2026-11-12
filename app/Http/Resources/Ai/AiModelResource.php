<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AiModel $resource
 */
class AiModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'provider_id' => $this->resource->provider_id,
            'key' => $this->resource->key,
            'display_name' => $this->resource->display_name,
            'capabilities' => $this->resource->capabilityList()
                ? array_map(static fn ($capability): string => $capability->value, $this->resource->capabilityList())
                : [],
            'is_active' => (bool) $this->resource->is_active,
            'is_default' => (bool) $this->resource->is_default,
            'input_price' => $this->resource->input_price !== null ? (float) $this->resource->input_price : null,
            'output_price' => $this->resource->output_price !== null ? (float) $this->resource->output_price : null,
            'context_window' => $this->resource->context_window,
            'notes' => $this->resource->notes,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
