<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiRequest;

/**
 * Cart-level details. The version is required so a save from a stale browser
 * cannot silently replace a teammate's quantity or price changes.
 */
class UpdateCartRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'shipping_total' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'shipping_address' => ['sometimes', 'nullable', 'array'],
            'shipping_address.contact_name' => ['nullable', 'string', 'max:180'],
            'shipping_address.line_one' => ['nullable', 'string', 'max:255'],
            'shipping_address.line_two' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_rate.max' => 'The tax rate must be a ratio between 0 and 1.',
        ];
    }
}
