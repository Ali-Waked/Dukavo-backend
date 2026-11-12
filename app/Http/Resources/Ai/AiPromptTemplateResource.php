<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiPromptTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A prompt template, with its versions when they are asked for.
 *
 * @property-read AiPromptTemplate $resource
 */
class AiPromptTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'task_id' => $this->resource->task_id,
            'task' => $this->whenLoaded('task', fn () => $this->resource->task ? [
                'id' => $this->resource->task->id,
                'key' => $this->resource->task->key,
                'label' => $this->resource->task->label,
            ] : null),
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'active_version_id' => $this->resource->active_version_id,
            'active_version' => new AiPromptVersionResource($this->whenLoaded('activeVersion') ? $this->resource->activeVersion : null),
            'versions' => AiPromptVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
