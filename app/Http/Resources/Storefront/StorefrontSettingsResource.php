<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Store;
use App\Services\Storefront\StorefrontSettings;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant-facing storefront configuration.
 *
 * Both language columns are returned so the editor can preserve an untouched
 * translation. `localized_settings` is the same document resolved for the
 * current interface language, for previews that need one language at a time.
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
        $locale = PreferredLocale::from($request);
        $settings = StorefrontSettings::merge($this->storefront_settings);

        return [
            'store_id' => $this->id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'localized_name' => $this->resource->localizedName($locale),
            'slug' => $this->slug,
            'status' => $this->status->value,
            'description' => $this->description,
            'description_ar' => $this->description_ar,
            'localized_description' => $this->resource->localizedDescription($locale),
            'logo_url' => $this->logo_url,
            'storefront_enabled' => $this->storefront_enabled,
            'currency' => $this->currency,
            'locale' => $locale,
            'enabled_locales' => $this->enabled_locales ?? (array) config('dukavo.locales.enabled_default', ['en', 'ar']),
            'settings' => $settings,
            'localized_settings' => StorefrontSettings::localized(
                $this->storefront_settings,
                $locale,
                $this->resource->approvedTranslations($locale),
            ),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
