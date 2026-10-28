<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiRequest;

class CancelCartRequest extends ApiRequest
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
