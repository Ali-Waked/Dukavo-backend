<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiRequest;

/**
 * Final confirmation. The expected total is a guard against confirming a cart
 * whose catalogue prices moved after the agent last looked at it.
 */
class ConfirmCartRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'expected_grand_total' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
