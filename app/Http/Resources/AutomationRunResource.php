<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationRun $resource
 */
class AutomationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_rule_id' => $this->automation_rule_id,
            'trigger' => $this->trigger->value,
            'trigger_label' => $this->trigger->label(),
            'event_key' => $this->event_key,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source,
            'attempts' => (int) $this->attempts,
            'matched' => (bool) $this->matched,
            'context' => $this->context ?? [],
            'actions' => $this->actions ?? [],
            'error' => $this->error,
            'duration_ms' => $this->duration(),
            'started_at' => $this->started_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'logs' => AutomationRunLogResource::collection($this->whenLoaded('logs')),
            'rule' => $this->whenLoaded('rule', fn () => $this->rule ? [
                'id' => $this->rule->id,
                'name' => $this->rule->name,
                'automation_template_id' => $this->rule->automation_template_id,
            ] : null),
        ];
    }
}
