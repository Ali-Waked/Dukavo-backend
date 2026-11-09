<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\ShippingZone;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant view of a delivery rule.
 *
 * Canonical English columns and the `_ar` aliases are both returned so the
 * editor can show two language tabs without losing either side. The raw
 * `translations` document is only exposed here; the public shop uses
 * {@see PublicShippingZoneResource}, which resolves it to one language.
 *
 * @property-read ShippingZone $resource
 */
class ShippingZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);
        $translations = $this->resource->translationDocument();
        $arabic = $translations['ar'] ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_ar' => $arabic['name'] ?? null,
            'description' => $this->description,
            'description_ar' => $arabic['description'] ?? null,
            'method_name' => $this->method_name,
            'method_name_ar' => $arabic['method_name'] ?? null,
            'method_description' => $this->method_description,
            'method_description_ar' => $arabic['method_description'] ?? null,
            'delivery_instructions' => $this->delivery_instructions,
            'delivery_instructions_ar' => $arabic['delivery_instructions'] ?? null,
            'terms' => $this->terms,
            'terms_ar' => $arabic['terms'] ?? null,
            'translations' => $translations,
            'localized' => $this->resource->localizedCopy($locale),
            'city' => $this->city,
            'areas' => $this->areas ?? [],
            'fee' => (float) $this->fee,
            'free_shipping_threshold' => $this->free_shipping_threshold === null
                ? null
                : (float) $this->free_shipping_threshold,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'locale' => $locale,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
