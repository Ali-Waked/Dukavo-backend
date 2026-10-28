<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationRule $resource
 */
class AutomationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'trigger' => $this->trigger->value,
            'trigger_label' => $this->trigger->label(),
            'conditions' => $this->conditions ?? [],
            'actions' => $this->actions ?? [],
            'cooldown_seconds' => (int) $this->cooldown_seconds,
            'run_count' => (int) $this->run_count,
            'last_triggered_at' => $this->last_triggered_at?->toIso8601String(),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'latest_run' => $this->whenLoaded('latestRun', fn () => $this->latestRun
                ? new AutomationRunResource($this->latestRun)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
