<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiRequest;

/**
 * A delete carries the same optimistic version as every other cart mutation.
 */
class RemoveCartItemRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
