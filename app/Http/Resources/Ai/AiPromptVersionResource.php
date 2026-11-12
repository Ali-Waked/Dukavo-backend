<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiPromptVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One immutable version of a prompt template.
 *
 * Versions are append-only; the resource is the same for history and for
 * the active pointer, and `is_active` is computed against the template's
 * pointer so a dashboard can mark it without a second query.
 *
 * @property-read AiPromptVersion $resource
 */
class AiPromptVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = $this->resource->relationLoaded('template') ? $this->resource->template : null;

        return [
            'id' => $this->resource->id,
            'template_id' => $this->resource->template_id,
            'version' => (int) $this->resource->version,
            'locale' => $this->resource->locale,
            'system_prompt' => $this->resource->system_prompt,
            'user_prompt' => $this->resource->user_prompt,
            'variables' => $this->resource->variables ?? [],
            'change_note' => $this->resource->change_note,
            'is_active' => $template !== null && $template->active_version_id === $this->resource->id,
            'created_by' => $this->whenLoaded('author', fn () => $this->resource->author ? [
                'id' => $this->resource->author->id,
                'name' => $this->resource->author->name,
            ] : null),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
