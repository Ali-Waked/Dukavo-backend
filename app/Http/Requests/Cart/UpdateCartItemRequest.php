<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiRequest;

class UpdateCartItemRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'discount_total' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }
}
