<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ApiRequest;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * A stock movement request.
 *
 * `quantity` is always positive here. The *direction* of the movement comes from
 * the endpoint (adjust, receive, issue) and from the movement type, so a client
 * cannot post a negative quantity and have the service guess the intent.
 */
class AdjustStockRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', Rule::exists(Store::class, 'id')],
            'product_variant_id' => ['required', 'integer', Rule::exists(ProductVariant::class, 'id')],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
