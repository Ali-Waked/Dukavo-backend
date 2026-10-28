<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ApiRequest;

/**
 * A transfer is intentionally narrow: one variant, one source, one destination.
 *
 * Both stores are checked against the active tenant, and the destination must
 * differ from the source, so a malformed request cannot turn into a no-op
 * movement pair or touch another merchant's stock.
 */
class TransferStockRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_store_id' => ['required', 'integer', $this->tenantExists('stores')],
            'to_store_id' => ['required', 'integer', 'different:from_store_id', $this->tenantExists('stores')],
            'product_variant_id' => ['required', 'integer', $this->tenantExists('product_variants')],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }
}
