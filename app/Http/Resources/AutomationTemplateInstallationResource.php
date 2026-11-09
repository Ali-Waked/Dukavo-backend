<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationTemplateInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationTemplateInstallation $resource
 */
class AutomationTemplateInstallationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preview = $this->getAttribute('update_preview');

        return [
            'id' => $this->id,
            'automation_template_id' => $this->automation_template_id,
            'automation_rule_id' => $this->automation_rule_id,
            'store_id' => $this->store_id,
            'scope_key' => $this->scope_key,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'installed_version' => $this->installed_version,
            'template_customized' => (bool) $this->template_customized,
            'configuration' => $this->configuration ?? [],
            'installed_at' => $this->installed_at?->toIso8601String(),
            'enabled_at' => $this->enabled_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'template' => $this->whenLoaded('template', fn () => $this->template ? [
                'id' => $this->template->id,
                'key' => $this->template->key,
                'name' => $this->template->name,
                'description' => $this->template->description,
                'category' => $this->template->category,
                'icon' => $this->template->icon,
                'trigger' => $this->template->trigger->value,
                'trigger_label' => $this->template->trigger->label(),
                'current_version' => $this->template->current_version,
                'configuration_schema' => $this->template->configuration_schema ?? [],
            ] : null),
            'rule' => $this->whenLoaded('rule', fn () => $this->rule ? [
                'id' => $this->rule->id,
                'name' => $this->rule->name,
                'status' => $this->rule->status->value,
                'status_label' => $this->rule->status->label(),
                'run_count' => (int) $this->rule->run_count,
                'last_triggered_at' => $this->rule->last_triggered_at?->toIso8601String(),
            ] : null),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ] : null),
            'installer' => $this->whenLoaded('installer', fn () => $this->installer ? [
                'id' => $this->installer->id,
                'name' => $this->installer->name,
            ] : null),
            'update_preview' => is_array($preview) ? $preview : null,
            'events' => AutomationInstallationEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
