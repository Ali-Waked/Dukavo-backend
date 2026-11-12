<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One account-security event as its owner sees it.
 *
 * The resource is read-only by design: the history has no edit or delete
 * endpoint. It exposes where and when something happened, never a credential or
 * a one-time code — the logger already filtered those before the row was
 * written.
 *
 * @property-read SecurityEvent $resource
 */
class SecurityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->event;

        return [
            'id' => $this->id,
            'event' => $type instanceof SecurityEventType ? $type->value : (string) $type,
            'label' => $type instanceof SecurityEventType ? $type->label() : (string) $type,
            'category' => $type instanceof SecurityEventType ? $type->category() : 'account',
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'ip_address' => $this->ip_address,
            'device' => $this->device,
            'country_code' => $this->country_code,
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
