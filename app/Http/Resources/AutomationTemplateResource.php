<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationTemplate $resource
 */
class AutomationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $installation = $this->getAttribute('installation_state');

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'trigger' => $this->trigger->value,
            'trigger_label' => $this->trigger->label(),
            'trigger_category' => $this->trigger->category(),
            'conditions' => $this->conditions ?? [],
            'actions' => $this->actions ?? [],
            'configuration_schema' => $this->configuration_schema ?? [],
            'icon' => $this->icon,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_version' => $this->current_version,
            'sort_order' => (int) $this->sort_order,
            'installations_count' => $this->whenCounted('installations'),
            'installation' => $installation instanceof AutomationTemplateInstallation
                ? $this->installationRow($installation)
                : null,
            'installations' => AutomationTemplateInstallationResource::collection(
                $this->whenLoaded('installations'),
            ),
            'versions' => AutomationTemplateVersionResource::collection(
                $this->whenLoaded('versions'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installationRow(AutomationTemplateInstallation $installation): array
    {
        return [
            'id' => $installation->id,
            'automation_rule_id' => $installation->automation_rule_id,
            'store_id' => $installation->store_id,
            'scope_key' => $installation->scope_key,
            'status' => $installation->status->value,
            'installed_version' => $installation->installed_version,
            'current_version' => $this->current_version,
            'update_available' => $installation->hasUpdate(),
            'template_customized' => (bool) $installation->template_customized,
            'installed_at' => $installation->installed_at?->toIso8601String(),
            'enabled_at' => $installation->enabled_at?->toIso8601String(),
        ];
    }
}
