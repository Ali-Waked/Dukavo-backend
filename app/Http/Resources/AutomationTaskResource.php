<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationTask $resource
 */
class AutomationTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_rule_id' => $this->automation_rule_id,
            'automation_run_id' => $this->automation_run_id,
            'store_id' => $this->store_id,
            'assigned_to' => $this->assigned_to,
            'created_by' => $this->created_by,
            'completed_by' => $this->completed_by,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'metadata' => $this->metadata ?? [],
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'rule' => $this->whenLoaded('rule', fn () => $this->rule ? [
                'id' => $this->rule->id,
                'name' => $this->rule->name,
            ] : null),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'completer' => $this->whenLoaded('completer', fn () => $this->completer ? [
                'id' => $this->completer->id,
                'name' => $this->completer->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
