<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CustomerSummary $resource
 */
class CustomerSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'summary' => $this->summary,
            'highlights' => $this->highlights ?? [],
            'risks' => $this->risks ?? [],
            'opportunities' => $this->opportunities ?? [],
            'source_context' => $this->source_context ?? [],
            'model' => $this->model,
            'is_edited' => (bool) $this->is_edited,
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator ? [
                'id' => $this->generator->id,
                'name' => $this->generator->name,
            ] : null),
            'edited_by' => $this->whenLoaded('editor', fn () => $this->editor ? [
                'id' => $this->editor->id,
                'name' => $this->editor->name,
            ] : null),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'refreshed_at' => $this->refreshed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
