<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\BillingInterval;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a pricing plan.
 *
 * A nullable price is valid and means "coming soon": the marketing site must
 * be able to publish a plan without an invented number. `limits` values may be
 * numbers or short text ("100" or "Unlimited"), so a closure rule accepts any
 * scalar and rejects arrays, which would otherwise silently nest in the JSON.
 */
class StorePricingPlanRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', 'unique:pricing_plans,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'billing_interval' => ['required', Rule::enum(BillingInterval::class)],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'features' => ['nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:150'],
            'limits' => ['nullable', 'array', 'max:30'],
            'limits.*' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The '.$attribute.' must be a number or a short piece of text.');
                }
            }],
            'highlighted' => ['boolean'],
            'active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
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
