<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PricingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PricingPlan $resource
 */
class PricingPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            // A null price means "not published yet": the frontend renders
            // coming-soon copy instead of inventing a number.
            'price' => $this->price !== null ? (float) $this->price : null,
            // Publication and purchasability are different questions: a plan
            // can be published (`active`) and still not sellable. The client
            // uses this flag, not the mere presence of a price, to decide
            // between a real signup CTA and a "not available yet" state.
            'purchase_available' => $this->isPurchasable(),
            'billing_interval' => $this->billing_interval->value,
            'billing_interval_label' => $this->billing_interval->label(),
            'currency' => $this->currency,
            'features' => $this->features,
            'limits' => $this->limits,
            'highlighted' => $this->highlighted,
            'active' => $this->active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Whether this plan can actually be bought right now.
     *
     * The Free plan (price 0) is always purchasable — it is pure registration.
     * Any paid plan stays unpurchasable until a payment provider and checkout
     * have been deliberately enabled, so the public page can never advertise a
     * checkout that does not exist. A null (unpublished) price is never
     * purchasable.
     */
    private function isPurchasable(): bool
    {
        if ($this->price === null) {
            return false;
        }

        if ((float) $this->price <= 0.0) {
            return true;
        }

        return config('dukavo.billing.checkout_enabled') === true
            && config('dukavo.billing.provider') !== null;
    }
}
