<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Identity\CountryCatalog;
use App\Support\Auth\DeviceInspector;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in client, backed by a Sanctum personal access token.
 *
 * `device` is the label written when the token was minted; browser and platform
 * are derived from the stored User-Agent for display only. `is_current` keeps
 * the caller's own session out of the "revoke" affordance, so signing out every
 * other device never signs the user out of the one they are using.
 *
 * @property-read PersonalAccessToken $resource
 */
class ProfileSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $described = DeviceInspector::describe($this->user_agent);
        $country = $this->country_code === null
            ? null
            : app(CountryCatalog::class)->find($this->country_code);
        $arabic = str_starts_with(PreferredLocale::from($request), 'ar');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'device' => $this->name,
            'browser' => $described['browser'],
            'platform' => $described['platform'],
            'device_type' => $described['device_type'],
            'ip_address' => $this->ip_address,
            'country_code' => $this->country_code,
            'country_name' => $country === null
                ? null
                : ($arabic ? $country['name_ar'] : $country['name_en']),
            'first_login_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_activity_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_current' => $currentId !== null && $this->id === $currentId,
        ];
    }
}
