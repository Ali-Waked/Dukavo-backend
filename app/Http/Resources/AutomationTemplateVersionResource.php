<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationTemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationTemplateVersion $resource
 */
class AutomationTemplateVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'trigger' => $this->trigger,
            'conditions' => $this->conditions ?? [],
            'actions' => $this->actions ?? [],
            'configuration_schema' => $this->configuration_schema ?? [],
            'checksum' => $this->checksum,
            'changelog' => $this->changelog,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
