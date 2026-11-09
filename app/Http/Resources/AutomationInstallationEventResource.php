<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationInstallationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationInstallationEvent $resource
 */
class AutomationInstallationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'metadata' => $this->metadata ?? [],
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
