<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Http\Requests\ApiRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Placing an order.
 *
 * No price field exists anywhere in this payload. The client may say *what* and
 * *how many*; the service reads every unit price from the catalogue and computes
 * the totals, so a modified request cannot change what a customer is charged.
 * Only a discount is accepted, and only as an amount that the service caps at the
 * line total.
 */
class StoreOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', Rule::exists(Store::class, 'id')],
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')],
            'customer_name' => ['nullable', 'string', 'max:180'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.contact_name' => ['nullable', 'string', 'max:180'],
            'shipping_address.line_one' => ['nullable', 'string', 'max:255'],
            'shipping_address.line_two' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_total' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required_without:items.*.product_variant_id', 'integer', Rule::exists(Product::class, 'id')],
            'items.*.product_variant_id' => ['required_without:items.*.product_id', 'integer', Rule::exists(ProductVariant::class, 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.discount_total' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.product_id.required_without' => 'Each line needs a product or a variant.',
            'items.*.product_variant_id.required_without' => 'Each line needs a product or a variant.',
            'tax_rate.max' => 'The tax rate must be a ratio between 0 and 1.',
        ];
    }

    /**
     * Validated data ready for the service.
     *
     * `shipping_address` is an array in the API but a JSON document in the
     * column, so it is encoded here rather than in the service: the service deals
     * only in stored representations.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (isset($data['shipping_address']) && is_array($data['shipping_address'])) {
            $data['shipping_address'] = json_encode($data['shipping_address'], JSON_THROW_ON_ERROR);
        }

        return $data;
    }
}
