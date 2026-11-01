<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Store;
use App\Services\Currency\CurrencyCatalog;
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
        $currency = $catalog->supports($this->currency) ? $catalog->meta((string) $this->currency) : null;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'slug' => $this->slug,
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
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
