<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\ShippingZone;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shopper view of a delivery option.
 *
 * Copy is resolved to a single language on the server. Operational values —
 * fee, threshold, city, areas, active flag, order — are passed through
 * unchanged, so the price a shopper sees cannot change with the language.
 * The raw translations document is deliberately never included.
 *
 * @property-read ShippingZone $resource
 */
class PublicShippingZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);
        $copy = $this->resource->localizedCopy($locale);

        return [
            'id' => $this->id,
            'name' => $copy['name'],
            'description' => $copy['description'],
            'method_name' => $copy['method_name'],
            'method_description' => $copy['method_description'],
            'delivery_instructions' => $copy['delivery_instructions'],
            'terms' => $copy['terms'],
            'city' => $this->city,
            'areas' => $this->areas ?? [],
            'fee' => (float) $this->fee,
            'free_shipping_threshold' => $this->free_shipping_threshold === null
                ? null
                : (float) $this->free_shipping_threshold,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'locale' => $locale,
        ];
    }
}
