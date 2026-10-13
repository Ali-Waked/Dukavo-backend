<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Http\Requests\ApiRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\Rule;

/**
 * Updating an order.
 *
 * Only orders that have not started fulfilment may be edited, which the service
 * enforces. The status itself is *not* editable here: moving an order through the
 * workflow is a separate action so that stock and history side effects always run.
 */
class UpdateOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'shipping_total' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'shipping_address' => ['sometimes', 'nullable', 'array'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required_without:items.*.product_variant_id', 'integer', Rule::exists(Product::class, 'id')],
            'items.*.product_variant_id' => ['required_without:items.*.product_id', 'integer', Rule::exists(ProductVariant::class, 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.discount_total' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }

    /**
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
