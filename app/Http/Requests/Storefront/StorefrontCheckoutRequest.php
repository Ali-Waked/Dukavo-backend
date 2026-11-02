<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Enums\PaymentMethod;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Public checkout payload.
 *
 * There is no price, fee or total field: the request says what to buy, where to
 * deliver it and how it will be paid, and every amount is resolved on the
 * server.
 */
class StorefrontCheckoutRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:180'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'customer_email' => ['nullable', 'email:rfc', 'max:190'],
            'area' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.contact_name' => ['nullable', 'string', 'max:180'],
            'shipping_address.line_one' => ['required', 'string', 'max:255'],
            'shipping_address.line_two' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required_without:items.*.product_id', 'integer', 'min:1'],
            'items.*.product_id' => ['required_without:items.*.product_variant_id', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.product_variant_id.required_without' => 'Each item needs a product or a variant.',
            'items.*.product_id.required_without' => 'Each item needs a product or a variant.',
        ];
    }
}
