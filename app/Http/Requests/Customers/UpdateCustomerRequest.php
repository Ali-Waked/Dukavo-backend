<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\ApiRequest;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Updating a customer.
 *
 * The address set is replaced when supplied: an address id that is not part of
 * the submitted set is removed, which is the only way to express "remove this
 * address" in a single request.
 */
class UpdateCustomerRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'locale' => ['sometimes', 'string', 'max:10'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'store_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Store::class, 'id')],
            'addresses' => ['sometimes', 'array', 'max:10'],
            'addresses.*.id' => ['sometimes', 'integer'],
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
