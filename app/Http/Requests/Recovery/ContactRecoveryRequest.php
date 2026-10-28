<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class ContactRecoveryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'string', Rule::in(['text', 'template'])],
            'body' => ['required_if:mode,text', 'nullable', 'string', 'max:4096'],
            'template_name' => ['required_if:mode,template', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language_code' => ['required_if:mode,template', 'nullable', 'string', 'max:12'],
            'parameters' => ['sometimes', 'nullable', 'array', 'max:10'],
            'parameters.*' => ['string', 'max:500'],
        ];
    }
}
