<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CurrencyConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A currency conversion receipt.
 *
 * @property-read CurrencyConversion $resource
 */
class CurrencyConversionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'from_currency' => $this->from_currency,
            'to_currency' => $this->to_currency,
            'exchange_rate' => (float) $this->exchange_rate,
            'rate_source' => $this->rate_source,
            'rate_fetched_at' => $this->rate_fetched_at?->toIso8601String(),
            'status' => $this->status,
            'products_converted' => $this->products_converted,
            'variants_converted' => $this->variants_converted,
            'bundles_converted' => $this->bundles_converted,
            'shipping_zones_converted' => $this->shipping_zones_converted,
            'failure_reason' => $this->failure_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
