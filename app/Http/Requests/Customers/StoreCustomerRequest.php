<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\ApiRequest;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Creating a customer.
 *
 * Addresses arrive as a nested array because they belong to the customer as a
 * set: at most one address per type may be the default, and that is only
 * enforceable while the whole set is being written.
 */
class StoreCustomerRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'locale' => ['sometimes', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'store_id' => ['nullable', 'integer', Rule::exists(Store::class, 'id')],
            'addresses' => ['sometimes', 'array', 'max:10'],
            'addresses.*.type' => ['sometimes', Rule::in(['shipping', 'billing'])],
            'addresses.*.contact_name' => ['required', 'string', 'max:180'],
            'addresses.*.line_one' => ['required', 'string', 'max:255'],
            'addresses.*.line_two' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.state' => ['nullable', 'string', 'max:120'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.country' => ['required', 'string', 'size:2'],
            'addresses.*.is_default' => ['sometimes', 'boolean'],
        ];
    }
}
