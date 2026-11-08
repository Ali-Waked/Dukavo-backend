<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Store;
use App\Services\Currency\CurrencyCatalog;
use App\Services\Storefront\StorefrontSettings;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Store $resource
 */
class PublicStorefrontResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalog = app(CurrencyCatalog::class);
        $locale = PreferredLocale::from($request);
        $currency = $catalog->supports($this->currency) ? $catalog->meta((string) $this->currency, $locale) : null;

        return [
            'name' => $this->resource->localizedName($locale),
            'slug' => $this->slug,
            'tenant_slug' => $this->whenLoaded('tenant', fn () => $this->tenant?->slug),
            'description' => $this->resource->localizedDescription($locale),
            'logo_url' => $this->logo_url,
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'currency_name' => $currency['name'] ?? null,
            'currency_symbol' => $currency['symbol'] ?? null,
            'currency_decimals' => $this->currency_decimals ?? $currency['decimals'] ?? 2,
            'timezone' => $this->timezone,
            'locale' => $locale,
            'settings' => StorefrontSettings::localized($this->storefront_settings, $locale),
        ];
    }
}
