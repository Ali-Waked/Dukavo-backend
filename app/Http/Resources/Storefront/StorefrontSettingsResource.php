<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Store;
use App\Services\Storefront\StorefrontSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant-facing storefront configuration.
 *
 * @property-read Store $resource
 */
class StorefrontSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'store_id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'storefront_enabled' => $this->storefront_enabled,
            'currency' => $this->currency,
            'settings' => StorefrontSettings::merge($this->storefront_settings),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
