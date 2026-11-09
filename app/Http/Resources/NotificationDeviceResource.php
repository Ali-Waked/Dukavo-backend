<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationDevice
 */
class NotificationDeviceResource extends JsonResource
{
    /**
     * The raw token is deliberately absent: the server uses it to send, and no
     * client needs it back after registration.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            'app_version' => $this->app_version,
            'locale' => $this->locale,
            'active' => $this->isActive(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
