<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Store;
use App\Services\Currency\CurrencyCatalog;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Store $resource
 */
class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalog = app(CurrencyCatalog::class);
        $locale = PreferredLocale::from($request);
        $currency = $catalog->supports($this->currency) ? $catalog->meta((string) $this->currency) : null;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'localized_name' => $this->resource->localizedName($locale),
            'slug' => $this->slug,
            'description' => $this->description,
            'description_ar' => $this->description_ar,
            'localized_description' => $this->resource->localizedDescription($locale),
            'logo_url' => $this->logo_url,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'currency_name' => $currency['name'] ?? null,
            'currency_symbol' => $currency['symbol'] ?? null,
            'currency_decimals' => $this->currency_decimals ?? $currency['decimals'] ?? 2,
            'currency_updated_at' => $this->currency_updated_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'is_default' => $this->is_default,
            'storefront_enabled' => $this->storefront_enabled,
            'locale' => $locale,
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
