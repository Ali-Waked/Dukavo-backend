<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Http\Requests\ApiRequest;

class SendTemplateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language_code' => ['required', 'string', 'max:15', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'parameters' => ['sometimes', 'array', 'max:20'],
            'parameters.*' => ['string', 'max:1000'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}
