<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiRequest;

class RequestCartConfirmationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'notify' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
