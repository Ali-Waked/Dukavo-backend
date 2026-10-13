<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\BillingInterval;
use App\Http\Requests\ApiRequest;
use App\Models\PricingPlan;
use Illuminate\Validation\Rule;

/**
 * Editing a pricing plan. Same constraints as the store request with every
 * field optional, so `PATCH` with only `{ "price": null }` unpublishes a
 * price without touching anything else.
 */
class UpdatePricingPlanRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:120', 'alpha_dash',
                Rule::unique(PricingPlan::class, 'slug')->ignore($this->route('pricingPlan')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'billing_interval' => ['sometimes', Rule::enum(BillingInterval::class)],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'features' => ['sometimes', 'nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:150'],
            'limits' => ['sometimes', 'nullable', 'array', 'max:30'],
            'limits.*' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The '.$attribute.' must be a number or a short piece of text.');
                }
            }],
            'highlighted' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'billing_interval' => 'billing interval',
            'sort_order' => 'sort order',
        ];
    }
}
