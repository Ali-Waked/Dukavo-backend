<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationRunLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AutomationRunLog $resource
 */
class AutomationRunLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level->value,
            'message' => $this->message,
            'context' => $this->context ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
